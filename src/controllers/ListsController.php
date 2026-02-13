<?php

namespace justinholtweb\dispatch\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ListsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('dispatch:accessPlugin');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('dispatch/lists/index', [
            'elementType' => MailingList::class,
        ]);
    }

    public function actionEdit(?int $listId = null, ?MailingList $list = null): Response
    {
        if ($list === null) {
            if ($listId !== null) {
                $list = Plugin::getInstance()->lists->getById($listId);
                if (!$list) {
                    throw new NotFoundHttpException('Mailing list not found.');
                }
            } else {
                $list = new MailingList();
            }
        }

        $isNew = !$list->id;

        return $this->renderTemplate('dispatch/lists/edit', [
            'list' => $list,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('dispatch', 'New Mailing List') : $list->title,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('dispatch:manageLists');

        $request = Craft::$app->getRequest();
        $listId = $request->getBodyParam('listId');

        if ($listId) {
            $list = Plugin::getInstance()->lists->getById($listId);
            if (!$list) {
                throw new NotFoundHttpException('Mailing list not found.');
            }
        } else {
            $list = new MailingList();
        }

        $list->name = $request->getBodyParam('name');
        $list->title = $list->name;
        $list->handle = $request->getBodyParam('handle') ?: StringHelper::toHandle($list->name);
        $list->description = $request->getBodyParam('description');

        $save = $listId ? Plugin::getInstance()->lists->update($list) : Plugin::getInstance()->lists->create($list);

        if (!$save) {
            Craft::$app->getSession()->setError(Craft::t('dispatch', 'Couldn't save mailing list.'));
            Craft::$app->getUrlManager()->setRouteParams(['list' => $list]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('dispatch', 'Mailing list saved.'));

        return $this->redirectToPostedUrl($list);
    }
}
