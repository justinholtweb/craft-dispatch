<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\models\Endpoint;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\services\Integrations;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Outgoing webhook endpoints (Pro). Admins only: an endpoint receives subscriber email addresses,
 * and its URL decides where the server makes requests to.
 *
 * Endpoints are stored in the database, not project config (each environment points at its own
 * receiver), so this needs an admin but not `allowAdminChanges`.
 */
class IntegrationsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        if (!Edition::isPro()) {
            throw new ForbiddenHttpException(Craft::t('dispatch', 'Integrations require Dispatch Pro.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('dispatch/integrations/index', [
            'endpoints' => Plugin::getInstance()->integrations->getAllEndpoints(),
        ]);
    }

    public function actionEdit(?int $endpointId = null, ?Endpoint $endpoint = null): Response
    {
        $service = Plugin::getInstance()->integrations;

        if ($endpoint === null) {
            $endpoint = $endpointId ? $service->getEndpointById($endpointId) : new Endpoint([
                'secret' => Integrations::generateSecret(),
                'events' => [Endpoint::EVENT_SUBSCRIBED, Endpoint::EVENT_UNSUBSCRIBED],
            ]);

            if (!$endpoint) {
                throw new NotFoundHttpException('Endpoint not found.');
            }
        }

        $isNew = !$endpoint->id;

        return $this->renderTemplate('dispatch/integrations/edit', [
            'endpoint' => $endpoint,
            'isNew' => $isNew,
            'eventOptions' => array_map(static fn(string $e) => ['label' => $e, 'value' => $e], Endpoint::EVENTS),
            'deliveries' => $isNew ? [] : $service->getDeliveries($endpoint->id),
            'title' => $isNew ? Craft::t('dispatch', 'New Endpoint') : $endpoint->name,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->integrations;
        $endpointId = (int)$request->getBodyParam('endpointId');

        $endpoint = $endpointId ? $service->getEndpointById($endpointId) : new Endpoint();

        if (!$endpoint) {
            throw new NotFoundHttpException('Endpoint not found.');
        }

        $endpoint->name = trim((string)$request->getBodyParam('name'));
        $endpoint->url = trim((string)$request->getBodyParam('url'));
        $endpoint->secret = trim((string)$request->getBodyParam('secret'));
        $endpoint->enabled = (bool)$request->getBodyParam('enabled');
        $events = $request->getBodyParam('events');
        $endpoint->events = is_array($events) ? array_map('strval', $events) : [];

        if (!$service->saveEndpoint($endpoint)) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn’t save endpoint.'));
            Craft::$app->getUrlManager()->setRouteParams(['endpoint' => $endpoint]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Endpoint saved.'));

        return $this->redirectToPostedUrl($endpoint);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->integrations->deleteEndpoint((int)Craft::$app->getRequest()->getRequiredBodyParam('endpointId'));
        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Endpoint deleted.'));

        return $this->redirect('dispatch/integrations');
    }

    /** Sends a signed `ping` to the saved endpoint now and says how it went. */
    public function actionTest(): Response
    {
        $this->requirePostRequest();

        $service = Plugin::getInstance()->integrations;
        $endpoint = $service->getEndpointById((int)Craft::$app->getRequest()->getRequiredBodyParam('endpointId'));

        if (!$endpoint) {
            throw new NotFoundHttpException('Endpoint not found.');
        }

        $result = $service->sendTest($endpoint);
        $session = Craft::$app->getSession();

        if ($result['ok']) {
            $session->setNotice(Craft::t('dispatch', 'Test delivered (HTTP {code}).', ['code' => $result['status']]));
        } else {
            $session->setError(Craft::t('dispatch', 'Test failed: {error}', ['error' => $result['error']]));
        }

        return $this->redirect("dispatch/integrations/{$endpoint->id}");
    }

    public function actionRedeliver(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $endpointId = (int)$request->getRequiredBodyParam('endpointId');
        $ok = Plugin::getInstance()->integrations->redeliver((int)$request->getRequiredBodyParam('deliveryId'));

        $ok
            ? Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Delivery queued again.'))
            : Craft::$app->getSession()->setError(Craft::t('dispatch', 'Only a failed delivery can be sent again.'));

        return $this->redirect("dispatch/integrations/{$endpointId}");
    }
}
