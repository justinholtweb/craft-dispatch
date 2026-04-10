<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\mail\Message;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\helpers\CssInliner;
use justinholtweb\dispatch\helpers\TrackingHelper;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\SendLogRecord;

class Sender extends Component
{
    public function renderEmail(Campaign $campaign, ?Subscriber $subscriber = null): string
    {
        $view = Craft::$app->getView();
        $settings = Plugin::getInstance()->getSettings();

        // Build template context
        $context = [
            'campaign' => $campaign,
            'subscriber' => $subscriber,
            'settings' => $settings,
            'unsubscribeUrl' => $subscriber ? $this->_getUnsubscribeUrl($subscriber, $campaign) : '#',
        ];

        // Render body content
        $bodyHtml = $view->renderString($campaign->body ?? '', $context);

        // Render within layout template
        $templatePath = $campaign->templatePath ?: $settings->defaultTemplateLayout;

        $html = '';
        try {
            $oldMode = $view->getTemplateMode();
            $view->setTemplateMode($view::TEMPLATE_MODE_SITE);

            if ($view->doesTemplateExist($templatePath)) {
                $context['content'] = $bodyHtml;
                $html = $view->renderTemplate($templatePath, $context);
            } else {
                // Fall back to plugin default template
                $view->setTemplateMode($view::TEMPLATE_MODE_CP);
                $context['content'] = $bodyHtml;
                $html = $view->renderTemplate('dispatch/email/default', $context);
            }

            $view->setTemplateMode($oldMode);
        } catch (\Throwable $e) {
            Craft::error("Failed to render email template: " . $e->getMessage(), 'dispatch');
            $html = $bodyHtml;
        }

        // Inline CSS
        $html = CssInliner::inline($html);

        // Add tracking (Lite+ only)
        if ($subscriber && Edition::isLite() && $settings->enableTracking) {
            if ($settings->trackOpens) {
                $html = TrackingHelper::injectTrackingPixel($html, $campaign->id, $subscriber->id);
            }
            if ($settings->trackClicks) {
                $html = TrackingHelper::rewriteLinks($html, $campaign->id, $subscriber->id);
            }
        }

        return $html;
    }

    public function sendToSubscriber(Campaign $campaign, Subscriber $subscriber): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        $html = $this->renderEmail($campaign, $subscriber);
        $subject = Craft::$app->getView()->renderString($campaign->subject, [
            'subscriber' => $subscriber,
            'campaign' => $campaign,
        ]);

        $fromName = $campaign->fromName ?: $settings->defaultFromName ?: Craft::$app->getProjectConfig()->get('email.fromName') ?: Craft::$app->getSystemName();
        $fromEmail = $campaign->fromEmail ?: $settings->defaultFromEmail ?: Craft::$app->getProjectConfig()->get('email.fromEmail') ?: 'noreply@' . Craft::$app->getRequest()->getHostName();
        $replyTo = $campaign->replyToEmail ?: $settings->defaultReplyToEmail ?: null;

        $message = new Message();
        $message->setTo($subscriber->email);
        $message->setFrom([$fromEmail => $fromName]);
        $message->setSubject($subject);
        $message->setHtmlBody($html);
        $message->setTextBody(strip_tags($html));

        if ($replyTo) {
            $message->setReplyTo($replyTo);
        }

        // Add List-Unsubscribe headers (RFC 8058)
        $unsubscribeUrl = $this->_getUnsubscribeUrl($subscriber, $campaign);
        $message->getSwiftMessage()->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $message->getSwiftMessage()->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $logRecord = new SendLogRecord();
        $logRecord->campaignId = $campaign->id;
        $logRecord->subscriberId = $subscriber->id;
        $logRecord->sentAt = Db::prepareDateForDb(new \DateTime());

        try {
            $sent = Craft::$app->getMailer()->send($message);

            if ($sent) {
                $logRecord->status = 'sent';
                // Try to capture message ID
                try {
                    $messageId = $message->getSwiftMessage()->getId();
                    $logRecord->messageId = $messageId;
                } catch (\Throwable) {
                    // Not all transports provide a message ID
                }
            } else {
                $logRecord->status = 'failed';
                $logRecord->errorMessage = 'Mailer returned false';
            }
        } catch (\Throwable $e) {
            $logRecord->status = 'failed';
            $logRecord->errorMessage = $e->getMessage();
            Craft::error("Failed to send email to {$subscriber->email}: " . $e->getMessage(), 'dispatch');
        }

        $logRecord->save(false);

        return $logRecord->status === 'sent';
    }

    public function sendBatch(Campaign $campaign, array $subscribers): array
    {
        $results = ['sent' => 0, 'failed' => 0];
        $settings = Plugin::getInstance()->getSettings();

        foreach ($subscribers as $subscriber) {
            // Rate limiting
            if ($settings->sendRateLimit > 0) {
                usleep((int)(1_000_000 / $settings->sendRateLimit));
            }

            if ($this->sendToSubscriber($campaign, $subscriber)) {
                $results['sent']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    private function _getUnsubscribeUrl(Subscriber $subscriber, Campaign $campaign): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $params = [
            'sid' => $subscriber->id,
            'lid' => $campaign->mailingListId,
            'token' => TrackingHelper::generateToken($subscriber->id, $campaign->mailingListId ?? 0),
        ];

        if ($settings->unsubscribeUrl) {
            return $settings->unsubscribeUrl . '?' . http_build_query($params);
        }

        return UrlHelper::siteUrl('dispatch/unsubscribe', $params);
    }
}
