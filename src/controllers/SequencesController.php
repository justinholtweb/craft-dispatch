<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\models\Sequence;
use justinholtweb\dispatch\models\SequenceStep;
use justinholtweb\dispatch\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Drip sequences (Pro).
 */
class SequencesController extends Controller
{
    /** Delay units offered in the step table, in minutes. */
    private const UNITS = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('dispatch:accessPlugin');
        $this->requirePermission('dispatch:manageSequences');

        if (!Edition::isPro()) {
            throw new ForbiddenHttpException(Craft::t('dispatch', 'Drip sequences require Dispatch Pro.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $service = Plugin::getInstance()->sequences;
        $sequences = $service->getAll();
        $stats = [];

        foreach ($sequences as $sequence) {
            $stats[$sequence->id] = $service->getStats($sequence->id);
        }

        return $this->renderTemplate('dispatch/sequences/index', [
            'sequences' => $sequences,
            'stats' => $stats,
            'lists' => array_column(array_map(fn($l) => ['id' => $l->id, 'title' => $l->title], MailingList::find()->all()), 'title', 'id'),
        ]);
    }

    public function actionEdit(?int $sequenceId = null, ?Sequence $sequence = null): Response
    {
        $service = Plugin::getInstance()->sequences;

        if ($sequence === null) {
            $sequence = $sequenceId ? $service->getById($sequenceId) : new Sequence();

            if (!$sequence) {
                throw new NotFoundHttpException('Sequence not found.');
            }

            if ($sequence->id) {
                $sequence->steps = $service->getSteps($sequence->id);
            }
        }

        $isNew = !$sequence->id;

        $listOptions = [['label' => Craft::t('dispatch', '— Select a list —'), 'value' => '']];
        foreach (MailingList::find()->all() as $list) {
            $listOptions[] = ['label' => $list->title, 'value' => $list->id];
        }

        $campaignOptions = [['label' => '', 'value' => '']];
        foreach (Campaign::find()->status(null)->orderBy(['elements.dateCreated' => SORT_DESC])->all() as $campaign) {
            $campaignOptions[] = ['label' => $campaign->title, 'value' => $campaign->id];
        }

        // The editable table's rows, keyed by step ID so a re-saved step keeps its ID.
        $rows = [];
        foreach ($sequence->steps as $i => $step) {
            [$amount, $unit] = $this->_splitDelay($step->delayMinutes);
            $rows[$step->id ? (string)$step->id : "new{$i}"] = [
                'campaignId' => $step->campaignId,
                'delay' => $amount,
                'unit' => $unit,
            ];
        }

        return $this->renderTemplate('dispatch/sequences/edit', [
            'sequence' => $sequence,
            'isNew' => $isNew,
            'listOptions' => $listOptions,
            'campaignOptions' => $campaignOptions,
            'stepRows' => $rows,
            'triggerEvents' => $service->getTriggerEvents(),
            'stats' => $isNew ? null : $service->getStats($sequence->id),
            'title' => $isNew ? Craft::t('dispatch', 'New Sequence') : $sequence->name,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->sequences;
        $sequenceId = (int)$request->getBodyParam('sequenceId');

        $sequence = $sequenceId ? $service->getById($sequenceId) : new Sequence();

        if (!$sequence) {
            throw new NotFoundHttpException('Sequence not found.');
        }

        $sequence->name = trim((string)$request->getBodyParam('name'));
        $sequence->handle = trim((string)$request->getBodyParam('handle')) ?: StringHelper::toHandle($sequence->name);
        $sequence->triggerType = (string)$request->getBodyParam('triggerType', Sequence::TRIGGER_LIST);
        $sequence->triggerListId = (int)$request->getBodyParam('triggerListId') ?: null;
        $sequence->triggerEvent = trim((string)$request->getBodyParam('triggerEvent')) ?: null;
        $sequence->enabled = (bool)$request->getBodyParam('enabled');

        $steps = [];
        $rows = $request->getBodyParam('steps');

        foreach (is_array($rows) ? $rows : [] as $key => $row) {
            if (!is_array($row) || empty($row['campaignId'])) {
                continue;
            }

            $minutes = max(0, (int)($row['delay'] ?? 0)) * (self::UNITS[$row['unit'] ?? 'days'] ?? 1440);

            $steps[] = new SequenceStep([
                'id' => ctype_digit((string)$key) ? (int)$key : null,
                'campaignId' => (int)$row['campaignId'],
                'delayMinutes' => $minutes,
            ]);
        }

        $sequence->steps = $steps;

        if (!$service->save($sequence)) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn’t save sequence.'));
            Craft::$app->getUrlManager()->setRouteParams(['sequence' => $sequence]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Sequence saved.'));

        return $this->redirectToPostedUrl($sequence);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->sequences->delete((int)Craft::$app->getRequest()->getRequiredBodyParam('sequenceId'));
        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Sequence deleted.'));

        return $this->redirect('dispatch/sequences');
    }

    /** @return array{0: int, 1: string} The largest whole unit a delay can be shown in. */
    private function _splitDelay(int $minutes): array
    {
        foreach (['days', 'hours'] as $unit) {
            if ($minutes > 0 && $minutes % self::UNITS[$unit] === 0) {
                return [intdiv($minutes, self::UNITS[$unit]), $unit];
            }
        }

        return [$minutes, $minutes === 0 ? 'days' : 'minutes'];
    }
}
