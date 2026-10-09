<?php

namespace justinholtweb\dispatch\variables;

use Craft;
use craft\helpers\Template;
use craft\web\View;
use justinholtweb\dispatch\controllers\SignupController;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\Plugin;
use Twig\Markup;

/**
 * `craft.dispatch` in site templates.
 *
 *     {{ craft.dispatch.signupForm('newsletter') }}
 *     {{ craft.dispatch.signupForm(['news', 'offers'], { chooseLists: true, askName: true,
 *         consentText: 'I agree to the [privacy policy](/privacy).', source: 'footer' }) }}
 *
 * Or build the form yourself and sign its configuration:
 *
 *     {{ hiddenInput('signup', craft.dispatch.signupConfig({ lists: 'newsletter' })) }}
 */
class DispatchVariable
{
    /**
     * A ready-made sign-up form: email (and optionally name) fields, the consent checkbox when
     * `consentText` is given, list checkboxes when `chooseLists` is on, a honeypot, CSRF, and the
     * form's errors or success message after it is posted. Unstyled — every element has a
     * `dispatch-signup…` class.
     *
     * Options: `consentText`, `source`, `chooseLists`, `askName`, `formId`, `redirect`,
     * `buttonLabel`, `emailLabel`, `class`.
     *
     * @param string|int|MailingList|array<int, string|int|MailingList> $lists Handles, IDs or lists.
     * @param array<string, mixed> $options
     */
    public function signupForm(string|int|MailingList|array $lists, array $options = []): Markup
    {
        $signups = Plugin::getInstance()->signups;
        $lists = is_array($lists) ? $lists : [$lists];
        $config = $signups->normalizeConfig(['lists' => $lists] + $options);
        $formId = is_string($options['formId'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $options['formId']) ? $options['formId'] : 'dispatch-signup';

        $posted = Craft::$app->getUrlManager()->getRouteParams()[SignupController::ROUTE_PARAM] ?? null;
        $posted = is_array($posted) && ($posted['form'] ?? null) === $formId ? $posted : null;

        $success = null;
        $session = Craft::$app->getSession();
        $flash = $session->getFlash(SignupController::FLASH, null, false);

        if (is_array($flash) && ($flash['form'] ?? null) === $formId) {
            $success = (string)($flash['message'] ?? '');
            $session->removeFlash(SignupController::FLASH);
        }

        $html = Craft::$app->getView()->renderTemplate('dispatch/_frontend/signup-form', [
            'formId' => $formId,
            'signup' => $signups->signConfig($config),
            'config' => $config,
            'consentHtml' => $config['consentText'] !== '' ? Template::raw(\justinholtweb\dispatch\helpers\Optin::html($config['consentText'])) : null,
            'lists' => $signups->resolveLists($config['lists']),
            'askName' => !empty($options['askName']),
            'redirect' => is_string($options['redirect'] ?? null) ? $options['redirect'] : null,
            'buttonLabel' => is_string($options['buttonLabel'] ?? null) ? $options['buttonLabel'] : Craft::t('dispatch', 'Subscribe'),
            'emailLabel' => is_string($options['emailLabel'] ?? null) ? $options['emailLabel'] : Craft::t('dispatch', 'Email'),
            'class' => is_string($options['class'] ?? null) ? $options['class'] : null,
            'honeypot' => SignupController::HONEYPOT,
            'errors' => $posted['errors'] ?? [],
            'values' => $posted['values'] ?? [],
            'success' => $success,
        ], View::TEMPLATE_MODE_CP);

        return Template::raw($html);
    }

    /**
     * The signed `signup` value for a hand-built form: `lists` (handles), `consentText`,
     * `source`, `chooseLists`.
     *
     * @param array<string, mixed> $config
     */
    public function signupConfig(array $config): string
    {
        return Plugin::getInstance()->signups->signConfig($config);
    }

    /** The honeypot field's name, for a hand-built form. */
    public function honeypotName(): string
    {
        return SignupController::HONEYPOT;
    }
}
