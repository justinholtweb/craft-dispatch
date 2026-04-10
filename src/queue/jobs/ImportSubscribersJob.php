<?php

namespace justinholtweb\dispatch\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\Plugin;
use RuntimeException;

class ImportSubscribersJob extends BaseJob
{
    public string $filePath = '';
    public int $mailingListId = 0;

    public function execute($queue): void
    {
        if (!file_exists($this->filePath)) {
            throw new RuntimeException("Import file not found: {$this->filePath}");
        }

        $handle = fopen($this->filePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Unable to open import file: {$this->filePath}");
        }

        // Count total rows for progress
        $totalRows = 0;
        while (fgetcsv($handle) !== false) {
            $totalRows++;
        }
        $totalRows--; // Subtract header row
        rewind($handle);

        if ($totalRows <= 0) {
            fclose($handle);
            @unlink($this->filePath);
            return;
        }

        $headers = fgetcsv($handle);
        $headers = array_map('strtolower', array_map('trim', $headers));
        $emailIndex = array_search('email', $headers);

        if ($emailIndex === false) {
            fclose($handle);
            @unlink($this->filePath);
            throw new RuntimeException('CSV must contain an "email" column.');
        }

        $firstNameIndex = array_search('firstname', $headers);
        if ($firstNameIndex === false) {
            $firstNameIndex = array_search('first_name', $headers);
        }
        $lastNameIndex = array_search('lastname', $headers);
        if ($lastNameIndex === false) {
            $lastNameIndex = array_search('last_name', $headers);
        }

        $subscribers = Plugin::getInstance()->subscribers;
        $rowNum = 0;
        $created = 0;
        $updated = 0;
        $failed = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $this->setProgress($queue, $rowNum / $totalRows, Craft::t('dispatch', 'Importing row {current} of {total}', [
                'current' => $rowNum,
                'total' => $totalRows,
            ]));

            $email = trim($row[$emailIndex] ?? '');
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                continue;
            }

            $existing = $subscribers->getByEmail($email);

            if ($existing) {
                if ($firstNameIndex !== false && !empty($row[$firstNameIndex])) {
                    $existing->firstName = trim($row[$firstNameIndex]);
                }
                if ($lastNameIndex !== false && !empty($row[$lastNameIndex])) {
                    $existing->lastName = trim($row[$lastNameIndex]);
                }

                if (Craft::$app->getElements()->saveElement($existing)) {
                    $updated++;
                } else {
                    $failed++;
                }

                $subscribers->subscribe($existing->id, $this->mailingListId);
            } else {
                $subscriber = new Subscriber();
                $subscriber->email = $email;
                $subscriber->firstName = $firstNameIndex !== false ? trim($row[$firstNameIndex] ?? '') : null;
                $subscriber->lastName = $lastNameIndex !== false ? trim($row[$lastNameIndex] ?? '') : null;
                $subscriber->status = 'active';

                if (Craft::$app->getElements()->saveElement($subscriber)) {
                    $created++;
                    $subscribers->subscribe($subscriber->id, $this->mailingListId);
                } else {
                    $failed++;
                }
            }
        }

        fclose($handle);
        @unlink($this->filePath);

        Craft::info("Import complete: {$created} created, {$updated} updated, {$failed} failed", 'dispatch');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('dispatch', 'Importing subscribers from CSV');
    }
}
