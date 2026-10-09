<?php

namespace justinholtweb\dispatch\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\dispatch\Plugin;
use yii\console\ExitCode;

/**
 * Sends whatever Dispatch has falling due. The queue already does this on its own; run it from
 * cron as well (`* * * * * php craft dispatch/scheduler/run`) on sites whose queue only runs on
 * web requests, so drip steps and A/B winners go out on time on a quiet night.
 */
class SchedulerController extends Controller
{
    public function actionRun(): int
    {
        $results = Plugin::getInstance()->scheduler->run();

        $this->stdout(sprintf(
            "Scheduled campaigns started: %d\nA/B winners picked: %d\nDrip emails processed: %d\n",
            $results['campaigns'],
            $results['abTests'],
            $results['sequenceSends'],
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
