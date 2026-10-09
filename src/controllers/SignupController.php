<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\dispatch\helpers\Optin;
use justinholtweb\dispatch\helpers\RateLimit;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\services\Signups;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * The front-end sign-up form's action and the double opt-in confirmation page.
 *
 * `dispatch/signup/subscribe` is anonymous and CSRF-protected. The lists it may sign anyone up to
 * come only from the form's signed `signup` value ({@see Signups::signConfig()}), so a visitor
 * can't add an address to a list the template author didn't offer. Its answer depends only on
 * whether the form was filled in properly — never on whether the address is known.
 *
 * The confirmation link opens `dispatch/confirm`, which changes nothing on GET: it shows a
 * Confirm button, and the POST that button sends is what confirms. Mail scanners and link
 * previewers open every link in an email, and a GET that confirmed would subscribe everybody
 * whose mail provider checks links.
 */
class SignupController extends Controller
{
    protected array|int|bool $allowAnonymous = ['subscribe', 'confirm'];

    /** The honeypot field: hidden from people, filled in by bots. */
    public const HONEYPOT = 'dispatchEmailAgain';

    /** Confirmation page views and attempts one address may make in a minute. */
    public const CONFIRM_PER_MINUTE = 20;

    /** Route param a failed sign-up hands back to the page, for `craft.dispatch.signupForm()`. */
    public const ROUTE_PARAM = 'dispatchSignup';

    /** Flash key a successful sign-up leaves, as `{form, message}`. */
    public const FLASH = 'dispatch-signup';

    public function actionSubscribe(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();
        $signups = Plugin::getInstance()->signups;
        $formId = $this->formId($request->getBodyParam('formId'));

        if (!RateLimit::allow('signup', max(1, $settings->signupsPerMinute))) {
            $message = Craft::t('dispatch', 'Too many sign-ups from here. Please try again in a minute.');

            if ($request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'message' => $message])->setStatusCode(429);
            }

            throw new TooManyRequestsHttpException($message);
        }

        $config = $signups->readConfig($request->getBodyParam('signup'));

        if ($config === null || $config['lists'] === []) {
            return $this->fail($formId, Craft::t('dispatch', 'This sign-up form isn’t set up correctly.'), [
                'form' => Craft::t('dispatch', 'This sign-up form isn’t set up correctly.'),
            ]);
        }

        $offered = $signups->resolveLists($config['lists']);
        $lists = $offered;

        // A form that lets the visitor choose posts `lists[]` (with an empty marker, so "chose
        // none" is told apart from "wasn't asked"). Only lists the form offers count.
        if ($config['chooseLists']) {
            $chosen = array_map('strval', array_filter((array)$request->getBodyParam('lists', []), 'is_scalar'));
            $lists = array_values(array_filter($offered, static fn($l) => in_array((string)$l->handle, $chosen, true) || in_array((string)$l->id, $chosen, true)));
        }

        $email = $request->getBodyParam('email');
        $email = is_string($email) ? trim($email) : '';
        $values = [
            'email' => $email,
            'firstName' => $this->stringParam('firstName'),
            'lastName' => $this->stringParam('lastName'),
        ];
        $errors = [];

        if ($email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = Craft::t('dispatch', 'Please enter a valid email address.');
        }

        if ($offered !== [] && $lists === []) {
            $errors['lists'] = Craft::t('dispatch', 'Choose at least one list.');
        }

        // A consent box is checked here as well as by the browser's `required`: the browser is
        // only a convenience, and a sign-up without the consent the form asked for isn't kept.
        if ($config['consentText'] !== '' && !Optin::ticked($request->getBodyParam('consent'))) {
            $errors['consent'] = Craft::t('dispatch', 'Please tick the box to agree.');
        }

        if ($offered === []) {
            Craft::warning('Dispatch: a sign-up form offers no mailing list that exists (' . implode(', ', $config['lists']) . ').', 'dispatch');
            $errors['form'] = Craft::t('dispatch', 'This sign-up form isn’t set up correctly.');
        }

        if ($errors !== []) {
            return $this->fail($formId, Craft::t('dispatch', 'Couldn’t sign you up.'), $errors, $values);
        }

        // A filled-in honeypot is a bot. It is told it succeeded, and nothing happens.
        $honeypot = $request->getBodyParam(self::HONEYPOT);

        if (!is_string($honeypot) || $honeypot === '') {
            $signups->start($email, array_map(static fn($l) => (int)$l->id, $lists), [
                'firstName' => $values['firstName'],
                'lastName' => $values['lastName'],
                'source' => $config['source'],
                'consentText' => $config['consentText'],
            ]);
        }

        return $this->succeed($formId, $settings->doubleOptIn);
    }

    /**
     * The confirmation page. GET shows a Confirm button for a good link; the button's POST
     * confirms. Both answer a bad, used or expired link with the same 404 page.
     */
    public function actionConfirm(): Response
    {
        if (!RateLimit::allow('confirm', self::CONFIRM_PER_MINUTE)) {
            throw new TooManyRequestsHttpException(Craft::t('dispatch', 'Too many attempts. Please try again in a minute.'));
        }

        $request = Craft::$app->getRequest();
        $signups = Plugin::getInstance()->signups;
        $lists = [];

        if ($request->getIsPost()) {
            $code = $request->getBodyParam('code');
            $subscriber = $signups->confirm($code);
            $state = $subscriber !== null ? 'confirmed' : 'invalid';
        } else {
            $code = $request->getQueryParam('code');
            $pending = $signups->findPending($code);
            $state = $pending !== null ? 'ask' : 'invalid';

            if ($pending !== null) {
                $ids = is_string($pending->listIds) ? json_decode($pending->listIds, true) : $pending->listIds;
                $lists = $signups->resolveLists(is_array($ids) ? $ids : []);
            }
        }

        // The token is in the URL: keep it out of caches, out of the Referer header sent to
        // anything the page links to, and out of search results. Set again once the response is
        // prepared, because SEO plugins (SEOmatic does) write their own Referrer-Policy and
        // robots headers on every page as it is sent.
        $privacy = static function(\yii\web\HeaderCollection $headers): void {
            $headers->set('Cache-Control', 'private, no-store');
            $headers->set('Referrer-Policy', 'no-referrer');
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        };
        $privacy($this->response->getHeaders());
        $this->response->on(\yii\web\Response::EVENT_AFTER_PREPARE, static fn(\yii\base\Event $e) => $privacy($e->sender->getHeaders()));

        if ($state === 'invalid') {
            $this->response->setStatusCode(404);
        }

        return $this->renderPage([
            'state' => $state,
            // Only a code that is still good goes back into the page.
            'code' => $state === 'ask' ? (string)$code : null,
            'lists' => $lists,
            'siteName' => Craft::$app->getSites()->getCurrentSite()->getName(),
        ]);
    }

    /** The site's own template when one is set and exists, otherwise Dispatch's plain page. */
    private function renderPage(array $variables): Response
    {
        $template = trim(Plugin::getInstance()->getSettings()->confirmationTemplate);
        $view = Craft::$app->getView();

        if ($template !== '' && $view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            return $this->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        }

        return $this->renderTemplate('dispatch/_frontend/confirm', $variables, View::TEMPLATE_MODE_CP);
    }

    /**
     * The same answer for every sign-up that was filled in properly, whatever happened to it.
     */
    private function succeed(string $formId, bool $doubleOptIn): ?Response
    {
        $message = $doubleOptIn
            ? Craft::t('dispatch', 'Almost done — check your inbox for a link to confirm your subscription.')
            : Craft::t('dispatch', 'Thanks — you’re subscribed.');

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'confirm' => $doubleOptIn, 'message' => $message]);
        }

        Craft::$app->getSession()->setFlash(self::FLASH, ['form' => $formId, 'message' => $message]);

        return $this->asSuccess($message);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string|null> $values
     */
    private function fail(string $formId, string $message, array $errors, array $values = []): ?Response
    {
        return $this->asFailure($message, ['success' => false, 'errors' => $errors], [
            self::ROUTE_PARAM => ['form' => $formId, 'errors' => $errors, 'values' => $values],
        ]);
    }

    private function stringParam(string $name): ?string
    {
        $value = Craft::$app->getRequest()->getBodyParam($name);

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }

    private function formId(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) ? $value : 'dispatch-signup';
    }
}
