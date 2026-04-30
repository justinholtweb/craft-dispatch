<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\dispatch\helpers\TrackingHelper;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;
use yii\web\Response;

class TrackingController extends Controller
{
    protected array|int|bool $allowAnonymous = ['open', 'click'];
    public $enableCsrfValidation = false;

    public function actionOpen(): Response
    {
        $request = Craft::$app->getRequest();
        $campaignId = (int)$request->getQueryParam('cid');
        $subscriberId = (int)$request->getQueryParam('sid');
        $token = $request->getQueryParam('token', '');

        if ($campaignId && $subscriberId && TrackingHelper::verifyToken($token, $campaignId, $subscriberId)) {
            try {
                Plugin::getInstance()->tracker->recordOpen(
                    $campaignId,
                    $subscriberId,
                    $request->getUserIP(),
                    $request->getUserAgent(),
                );
            } catch (\Throwable $e) {
                Craft::warning("Failed to record open: " . $e->getMessage(), 'dispatch');
            }
        }

        // Return 1x1 transparent GIF
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'image/gif');
        $response->getHeaders()->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->getHeaders()->set('Pragma', 'no-cache');
        $response->getHeaders()->set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');

        // Smallest valid 1x1 transparent GIF
        $response->content = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return $response;
    }

    public function actionClick(): Response
    {
        $request = Craft::$app->getRequest();
        $campaignId = (int)$request->getQueryParam('cid');
        $subscriberId = (int)$request->getQueryParam('sid');
        $url = $request->getQueryParam('url', '');
        $token = $request->getQueryParam('token', '');

        $siteHome = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();

        // The HMAC binds (campaignId, subscriberId, url). If verification fails,
        // the URL is attacker-controlled — never redirect to it (open redirect).
        if (!$url || !$campaignId || !$subscriberId
            || !TrackingHelper::verifyClickToken($token, $campaignId, $subscriberId, $url)) {
            return $this->redirect($siteHome);
        }

        try {
            Plugin::getInstance()->tracker->recordClick(
                $campaignId,
                $subscriberId,
                $url,
                $request->getUserIP(),
                $request->getUserAgent(),
            );
        } catch (\Throwable $e) {
            Craft::warning("Failed to record click: " . $e->getMessage(), 'dispatch');
        }

        return $this->redirect($url);
    }
}
