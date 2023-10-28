<?php
/**
 * autosave.php
 * Copyright (c) 2020 james@firefly-iii.org
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

bcscale(12);
/*
 * INSTRUCTIONS FOR USE.
 *
 * 1. READ THE DOCS AT: https://github.com/jc5/autosave
 *
 * Feel free to edit the code, read it and play with it. If you have questions feel free to ask them.
 * Keep in mind that running this script is entirely AT YOUR OWN RISK with ZERO GUARANTEES.
 */

const EXCLUDED_TAGS = 'Direct debit';
const FIREFLY_III_TOKEN = 'ey...';

/*
 * HERE BE MONSTERS
 *
 * BELOW THIS LINE IS ACTUAL CODE (TM).
 */


// get and validate arguments
$arguments = getArguments($argv);

message('Start of script. Welcome!');

// download account info from Firefly III.
message(sprintf('Downloading info on account #%d...', $arguments['account']));
$source = getAccount($arguments['account']);
message(sprintf('Downloading info on account #%d...', $arguments['destination']));
$destination = getAccount($arguments['destination']);

// time stamp x days ago, or 0 if 'days' is 0.
$timestamp = 0;
if (0 !== $arguments['days']) {
    $seconds   = $arguments['days'] * 24 * 60 * 60;
    $timestamp = time() - $seconds;
}
define('TIMESTAMP', $timestamp);

// die if either is not asset account
if ('asset' !== $source['data']['attributes']['type'] ?? 'invalid') {
    messageAndExit('Submit a valid asset account, using --account=x. This account is the account on which you auto-save money.');
}
if ('asset' !== $destination['data']['attributes']['type'] ?? 'invalid') {
    messageAndExit('Submit a valid destination (savings) asset account using --destination=x. This is the account on which the money is saved.');
}
message('Both accounts are valid asset accounts.');

// get transaction groups from account (withdrawals only):
message(sprintf('Downloading transactions for account #%d "%s"...', $source['data']['id'], $source['data']['attributes']['name']));
$groups = getTransactions((int) $source['data']['id']);

message(sprintf('Found %d transactions.', count($groups)));

/** @var array $group */
foreach ($groups as $group) {
    // split transactions arent supported.
    if (1 !== count($group['attributes']['transactions'])) {
        message(sprintf('Split transactions are not supported, so transaction #%d will be skipped.', $group['id']));
        continue;
    }

    // get the main transaction (we know it's one)
    $transaction = $group['attributes']['transactions'][0];

    // maybe already has a link to existing auto-save?
    $links          = getLinks($transaction);
    $createAutoSave = true;
    if (0 !== count($links)) {
        foreach ($links as $link) {
            $opposingTransactionId = getOpposingTransaction($transaction['transaction_journal_id'], $link);

            // if the opposing transaction is a transfer, and it's an autosave link (recognized by the tag)
            // we don't need to create another one.
            $opposingTransaction = getTransaction($opposingTransactionId);

            if (isAutoSaveTransaction($opposingTransaction)) {
                $createAutoSave = false;
            }
        }
    }
    if ($createAutoSave) {
        createAutoSaveTransaction($group, $arguments);
    }
}

/**
 * @param array $group
 * @param array $arguments
 *
 * @throws JsonException
 */
function createAutoSaveTransaction(array $group, array $arguments): void
{
    $first          = $group['attributes']['transactions'][0];
    $amount         = $first['amount'];
    $left           = bcmod($amount, (string) $arguments['amount']);
    $amountToCreate = bcsub((string) (string) $arguments['amount'], $left);

    if (0 === bccomp((string) (string) $arguments['amount'], $amountToCreate)) {
        // no need to create, is already exactly the auto save amount or a multiplier.
        return;
    }
    if ($arguments['dryrun']) {
        // report only:
        message(sprintf('For transaction #%d ("%s") with amount %s %s, would have created auto-save transaction with amount %s %s, making the total %s %s.',
                        $group['id'],
                        $first['description'],
                        $first['currency_code'],
                        number_format((float) $amount, 2, '.', ','),
                        $first['currency_code'],
                        number_format((float) $amountToCreate, 2, '.', ','),
                        $first['currency_code'],
                        number_format((float) bcadd($amountToCreate, $amount), 2, '.', ','),
                ));
        return;
    }
    // create transaction:
    $submission = [
        'transactions' => [
            [
                'type'           => 'transfer',
                'source_id'      => $arguments['account'],
                'destination_id' => $arguments['destination'],
                'description'    => '(auto save transaction)',
                'date'           => substr($first['date'], 0, 10),
                'tags'           => ['auto-save'],
                'currency_code'  => $first['currency_code'],
                'amount'         => $amountToCreate,
            ],
        ],
    ];
    // submit:
    $result  = postCurlRequest('/api/v1/transactions', $submission);
    $groupId = $result['data']['id'];
    message(sprintf('For transaction #%d ("%s") with amount %s %s, have created auto-save transaction #%d with amount %s %s, making the total %s %s.',
                    $group['id'],
                    $first['description'],
                    $first['currency_code'],
                    number_format((float) $amount, 2, '.', ','),
                    $groupId,
                    $first['currency_code'],
                    number_format((float) $amountToCreate, 2, '.', ','),
                    $first['currency_code'],
                    number_format((float) bcadd($amountToCreate, $amount), 2, '.', ','),
            ));

    $relationSubmission = [
        'link_type_id' => 1,
        'inward_id'    => $result['data']['attributes']['transactions'][0]['transaction_journal_id'],
        'outward_id'   => $first['transaction_journal_id'],
        'notes'        => 'Created to automatically save money.',
    ];
    // create a link between A and B.
    postCurlRequest('/api/v1/transaction-links', $relationSubmission);


}


/**
 * @param array $transaction
 * @return bool
 */
function isAutoSaveTransaction(array $transaction): bool
{
    // it's a split, then false:
    if (count($transaction['attributes']['transactions']) > 1) {
        return false;
    }
    $first = $transaction['attributes']['transactions'][0];

    // its not a transfer, so false:
    if ('transfer' !== $first['type']) {
        return false;
    }
    $hasTag = false;
    foreach ($first['tags'] as $tag) {
        if ('auto-save' === $tag) {
            return true;
        }
    }
    return false;
}

/**
 * @param int $journalId
 * @return array
 */
function getTransaction(int $journalId): array
{
    $opposing = getCurlRequest(sprintf('/api/v1/transaction-journals/%d', $journalId));
    return $opposing['data'];
}

/**
 * @param int   $transactionId
 * @param array $link
 * @return int
 */
function getOpposingTransaction(string $transactionId, array $link): int
{
    $opposingJournal = 0;
    if ($transactionId === $link['attributes']['inward_id']) {
        $opposingJournal = $link['attributes']['outward_id'];
    }
    if ($transactionId === $link['attributes']['outward_id']) {
        $opposingJournal = $link['attributes']['inward_id'];
    }
    if (0 === $opposingJournal) {
        messageAndExit('No opposing transaction.');
    }
    return (int)$opposingJournal;
}


/**
 * @param array $transaction
 *
 * @return array
 */
function getLinks(array $transaction): array
{
    $journalId = $transaction['transaction_journal_id'];
    $links     = getCurlRequest(sprintf('/api/v1/transaction-journals/%d/links', $journalId));

    if (count($links['data']) > 0) {
        return $links['data'];
    }
    return [];
}


/**
 * @param int $accountId
 *
 * @return array
 */
function getTransactions(int $accountId): array
{
    $return              = [];
    $page                = 1;
    $limit               = 75;
    $hasMoreTransactions = true;
    $count               = 0;

    while ($count < 5 && true === $hasMoreTransactions) {
        $result     = getCurlRequest(sprintf('/api/v1/accounts/%d/transactions?page=%d&limit=%d&type=withdrawal', $accountId, $page, $limit));
        $totalPages = (int) ($result['meta']['pagination']['total_pages'] ?? 0);

        // loop transactions to see if we've reached the required date.
        $currentSet = $result['data'];

        //message(sprintf('Found %d transaction(s) on page %d', count($currentSet), $page));

        /** @var array $currentGroup */
        foreach ($currentSet as $currentGroup) {
            $addToSet     = false;
            $transactions = $currentGroup['attributes']['transactions'] ?? [];
            /** @var array $transaction */
            foreach ($transactions as $transaction) {
                $time = strtotime($transaction['date']);
                if ($time > TIMESTAMP) {
                    $tags = $transaction['tags'];
                    $noExcludedTags = true;
                    foreach (explode(";", EXCLUDED_TAGS) as $excludedTag) {
                        if (in_array($tags, $excludedTag)) {
                            $noExcludedTags = false;
                        }
                    }
                    if ($noExcludedTags) {
                        // add it to the array:
                        $addToSet = true;
                    }
                }
                if ($time <= TIMESTAMP) {
                    //message(sprintf('Will not include transaction group #%d, the date is %s', $currentGroup['id'], $transaction['date']));
                    // break the loop:
                    $hasMoreTransactions = false;
                }
            }
            if ($addToSet) {
                $return[] = $currentGroup;
            }
        }

        // if $hasMoreTransactions isnt false already, compare total_pages to current page
        if (false !== $hasMoreTransactions) {
            $hasMoreTransactions = $totalPages > $page;
        }

        $page++;
        $count++;
    }
    //message('Stopped downloading transactions');

    return $return;
}

/**
 * @param int $accountId
 *
 * @return array
 */
function getAccount(int $accountId): array
{
    return getCurlRequest(sprintf('/api/v1/accounts/%d', $accountId));
}

/**
 * @param string $url
 *
 * @return array
 */
function getCurlRequest(string $url): array
{
    $ch = curl_init();
    curl_setopt(
        $ch, CURLOPT_HTTPHEADER,
        [
            'Content-Type: application/json',
            'Accept: application/json',
            sprintf('Authorization: Bearer %s', FIREFLY_III_TOKEN),
        ]
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_URL, sprintf('%s%s', FIREFLY_III_URL, $url));
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);

    // Execute
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (200 !== $httpCode) {
        $error = curl_error($ch);
        message(sprintf('Request %s returned with HTTP code %d.', $url, $httpCode));
        message($error);
        message((string) $result);
        messageAndExit('');
    }
    $body = [];
    try {
        $body = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        messageAndExit($e->getMessage());
    }

    return $body;
}

/**
 * @param string $url
 * @param array  $body
 * @return array
 * @throws JsonException
 */
function postCurlRequest(string $url, array $body): array
{
    //message(sprintf('Going to POST %s', $url));
    $ch = curl_init();
    curl_setopt(
        $ch, CURLOPT_HTTPHEADER,
        [
            'Content-Type: application/json',
            'Accept: application/json',
            sprintf('Authorization: Bearer %s', FIREFLY_III_TOKEN),
        ]
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_URL, sprintf('%s%s', FIREFLY_III_URL, $url));
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    // Execute
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (200 !== $httpCode) {
        $error = curl_error($ch);
        message(sprintf('Request %s returned with HTTP code %d.', $url, $httpCode));
        message($error);
        message((string) $result);
        messageAndExit('');
    }
    $body = [];
    try {
        $body = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        messageAndExit($e->getMessage());
    }

    return $body;
}

/**
 * @param array $arguments
 *
 * @return array
 */
function getArguments(array $arguments): array
{
    if (1 === count($arguments)) {
        message('To use this application:');
        message('');
        message('php autosave.php --account=x --destination=y --days=21 --amount=2.5');
        messageAndExit('');
    }

    $result = [
        'account'     => 0,
        'destination' => 0,
        'days'        => 0,
        'amount'      => 0.0,
        'dryrun'      => false,
    ];
    $fields = array_keys($result);
    /** @var string $argument */
    foreach ($arguments as $argument) {
        foreach ($fields as $field) {
            if (str_starts_with($argument, sprintf('--%s=', $field))) {
                $result[$field] = (int) str_replace(sprintf('--%s=', $field), '', $argument);
                if ('amount' === $field) {
                    $result[$field] = (float) str_replace(sprintf('--%s=', $field), '', $argument);
                }
            }
        }
    }
    if (in_array('--dry-run', $arguments)) {
        $result['dryrun'] = true;
    }
    if (0 === $result['account']) {
        messageAndExit('Submit a valid account, using --account=x. This account is the account on which you auto-save money.');
    }
    if (0 === $result['destination']) {
        messageAndExit('Submit a valid destination (savings) asset account using --destination=x. This is the account on which the money is saved.');
    }
    if (0 === $result['days']) {
        message('Not defining the number of days to go back will not improve performance.');
    }
    if (0.0 === $result['amount']) {
        messageAndExit('Submit the amount by which you save, ie. --amount=5 or --amount=2.5.');
    }

    return $result;
}

/**
 * @param string $message
 */
function message(string $message): void
{
    echo $message . "\n";
}

function messageAndExit(string $message): void
{
    message($message);
    exit;
}
