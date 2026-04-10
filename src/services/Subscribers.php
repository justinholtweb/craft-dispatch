<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\SubscriptionRecord;

class Subscribers extends Component
{
    public function getById(int $id): ?Subscriber
    {
        return Subscriber::find()->id($id)->one();
    }

    public function getByEmail(string $email): ?Subscriber
    {
        return Subscriber::find()->email($email)->one();
    }

    public function create(Subscriber $subscriber): bool
    {
        if (!$subscriber->subscribedAt) {
            $subscriber->subscribedAt = Db::prepareDateForDb(new \DateTime());
        }

        return Craft::$app->getElements()->saveElement($subscriber);
    }

    public function update(Subscriber $subscriber): bool
    {
        return Craft::$app->getElements()->saveElement($subscriber);
    }

    public function delete(Subscriber $subscriber): bool
    {
        return Craft::$app->getElements()->deleteElement($subscriber);
    }

    public function subscribe(int $subscriberId, int $mailingListId): bool
    {
        $existing = SubscriptionRecord::find()
            ->where(['subscriberId' => $subscriberId, 'mailingListId' => $mailingListId])
            ->one();

        if ($existing) {
            return true;
        }

        $record = new SubscriptionRecord();
        $record->subscriberId = $subscriberId;
        $record->mailingListId = $mailingListId;
        $record->subscribedAt = Db::prepareDateForDb(new \DateTime());

        $result = $record->save();

        if ($result) {
            $list = MailingList::find()->id($mailingListId)->one();
            if ($list) {
                $list->refreshSubscriberCount();
            }
        }

        return $result;
    }

    public function unsubscribe(int $subscriberId, ?int $mailingListId = null): bool
    {
        if ($mailingListId) {
            SubscriptionRecord::deleteAll([
                'subscriberId' => $subscriberId,
                'mailingListId' => $mailingListId,
            ]);

            $list = MailingList::find()->id($mailingListId)->one();
            if ($list) {
                $list->refreshSubscriberCount();
            }
        } else {
            // Unsubscribe from all lists
            $subscriptions = SubscriptionRecord::findAll(['subscriberId' => $subscriberId]);
            $listIds = array_map(fn($s) => $s->mailingListId, $subscriptions);

            SubscriptionRecord::deleteAll(['subscriberId' => $subscriberId]);

            foreach ($listIds as $listId) {
                $list = MailingList::find()->id($listId)->one();
                if ($list) {
                    $list->refreshSubscriberCount();
                }
            }
        }

        // Update subscriber status
        $subscriber = $this->getById($subscriberId);
        if ($subscriber) {
            $subscriber->status = 'unsubscribed';
            $subscriber->unsubscribedAt = Db::prepareDateForDb(new \DateTime());
            return Craft::$app->getElements()->saveElement($subscriber);
        }

        return true;
    }

    public function importCsv(string $filePath, int $mailingListId): array
    {
        $results = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];

        if (!file_exists($filePath)) {
            $results['errors'][] = 'File not found.';
            return $results;
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $results['errors'][] = 'Unable to open file.';
            return $results;
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            $results['errors'][] = 'Empty CSV file.';
            return $results;
        }

        $headers = array_map('strtolower', array_map('trim', $headers));
        $emailIndex = array_search('email', $headers);

        if ($emailIndex === false) {
            fclose($handle);
            $results['errors'][] = 'CSV must contain an "email" column.';
            return $results;
        }

        $firstNameIndex = array_search('firstname', $headers);
        if ($firstNameIndex === false) {
            $firstNameIndex = array_search('first_name', $headers);
        }
        $lastNameIndex = array_search('lastname', $headers);
        if ($lastNameIndex === false) {
            $lastNameIndex = array_search('last_name', $headers);
        }

        while (($row = fgetcsv($handle)) !== false) {
            $email = trim($row[$emailIndex] ?? '');
            if (empty($email)) {
                $results['failed']++;
                continue;
            }

            $existing = $this->getByEmail($email);

            if ($existing) {
                if ($firstNameIndex !== false && !empty($row[$firstNameIndex])) {
                    $existing->firstName = trim($row[$firstNameIndex]);
                }
                if ($lastNameIndex !== false && !empty($row[$lastNameIndex])) {
                    $existing->lastName = trim($row[$lastNameIndex]);
                }

                if (Craft::$app->getElements()->saveElement($existing)) {
                    $results['updated']++;
                } else {
                    $results['failed']++;
                }

                $this->subscribe($existing->id, $mailingListId);
            } else {
                $subscriber = new Subscriber();
                $subscriber->email = $email;
                $subscriber->firstName = $firstNameIndex !== false ? trim($row[$firstNameIndex] ?? '') : null;
                $subscriber->lastName = $lastNameIndex !== false ? trim($row[$lastNameIndex] ?? '') : null;
                $subscriber->status = 'active';

                if (Craft::$app->getElements()->saveElement($subscriber)) {
                    $results['created']++;
                    $this->subscribe($subscriber->id, $mailingListId);
                } else {
                    $results['failed']++;
                }
            }
        }

        fclose($handle);

        return $results;
    }

    public function exportCsv(?int $mailingListId = null): string
    {
        $query = Subscriber::find();

        if ($mailingListId) {
            $query->mailingListId($mailingListId);
        }

        $subscribers = $query->all();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['email', 'firstName', 'lastName', 'status', 'subscribedAt']);

        foreach ($subscribers as $subscriber) {
            fputcsv($output, [
                $subscriber->email,
                $subscriber->firstName,
                $subscriber->lastName,
                $subscriber->status,
                $subscriber->subscribedAt,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    public function syncCraftUsers(array $userGroupIds = []): array
    {
        $results = ['synced' => 0, 'created' => 0, 'updated' => 0];

        $userQuery = User::find();

        if (!empty($userGroupIds)) {
            $userQuery->groupId($userGroupIds);
        }

        $users = $userQuery->all();

        foreach ($users as $user) {
            $existing = Subscriber::find()->userId($user->id)->one();

            if ($existing) {
                $existing->email = $user->email;
                $existing->firstName = $user->firstName;
                $existing->lastName = $user->lastName;

                if (Craft::$app->getElements()->saveElement($existing)) {
                    $results['updated']++;
                }
            } else {
                // Check if there's already a subscriber with this email
                $existingByEmail = $this->getByEmail($user->email);

                if ($existingByEmail) {
                    $existingByEmail->userId = $user->id;
                    $existingByEmail->firstName = $existingByEmail->firstName ?: $user->firstName;
                    $existingByEmail->lastName = $existingByEmail->lastName ?: $user->lastName;

                    if (Craft::$app->getElements()->saveElement($existingByEmail)) {
                        $results['updated']++;
                    }
                } else {
                    $subscriber = new Subscriber();
                    $subscriber->email = $user->email;
                    $subscriber->firstName = $user->firstName;
                    $subscriber->lastName = $user->lastName;
                    $subscriber->userId = $user->id;
                    $subscriber->status = 'active';

                    if (Craft::$app->getElements()->saveElement($subscriber)) {
                        $results['created']++;
                    }
                }
            }

            $results['synced']++;
        }

        return $results;
    }
}
