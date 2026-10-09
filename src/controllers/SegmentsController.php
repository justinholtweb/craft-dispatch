<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\models\Segment;
use justinholtweb\dispatch\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Dynamic segments (Pro): saved subscriber conditions.
 */
class SegmentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('dispatch:accessPlugin');
        $this->requirePermission('dispatch:manageSegments');

        if (!Edition::isPro()) {
            throw new ForbiddenHttpException(Craft::t('dispatch', 'Segments require Dispatch Pro.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $segments = Plugin::getInstance()->segments->getAll();
        $counts = [];

        foreach ($segments as $segment) {
            try {
                $counts[$segment->id] = Plugin::getInstance()->segments->count($segment);
            } catch (\Throwable) {
                $counts[$segment->id] = null;
            }
        }

        return $this->renderTemplate('dispatch/segments/index', [
            'segments' => $segments,
            'counts' => $counts,
        ]);
    }

    public function actionEdit(?int $segmentId = null, ?Segment $segment = null): Response
    {
        if ($segment === null) {
            $segment = $segmentId ? Plugin::getInstance()->segments->getById($segmentId) : new Segment();

            if (!$segment) {
                throw new NotFoundHttpException('Segment not found.');
            }
        }

        $isNew = !$segment->id;

        return $this->renderTemplate('dispatch/segments/edit', [
            'segment' => $segment,
            'isNew' => $isNew,
            'matching' => $isNew ? null : Plugin::getInstance()->segments->count($segment),
            'title' => $isNew ? Craft::t('dispatch', 'New Segment') : $segment->name,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->segments;
        $segmentId = (int)$request->getBodyParam('segmentId');

        $segment = $segmentId ? $service->getById($segmentId) : new Segment();

        if (!$segment) {
            throw new NotFoundHttpException('Segment not found.');
        }

        $segment->name = trim((string)$request->getBodyParam('name'));
        $segment->handle = trim((string)$request->getBodyParam('handle')) ?: StringHelper::toHandle($segment->name);
        $segment->description = $request->getBodyParam('description') ?: null;

        // The builder posts the condition's whole config, class included. Only its rules are
        // taken; the condition class is always Dispatch's (Segment::getCondition()).
        $posted = $request->getBodyParam('condition');
        $segment->condition = ['conditionRules' => is_array($posted) ? ($posted['conditionRules'] ?? []) : []];

        if (!$service->save($segment)) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn’t save segment.'));
            Craft::$app->getUrlManager()->setRouteParams(['segment' => $segment]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Segment saved.'));

        return $this->redirectToPostedUrl($segment);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->segments->delete((int)Craft::$app->getRequest()->getRequiredBodyParam('segmentId'));
        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Segment deleted.'));

        return $this->redirect('dispatch/segments');
    }
}
