<?php
/**
 * SugarCRM Community Edition is a customer relationship management program developed by
 * SugarCRM, Inc. Copyright (C) 2004-2013 SugarCRM Inc.
 *
 * SuiteCRM is an extension to SugarCRM Community Edition developed by SalesAgility Ltd.
 * Copyright (C) 2011 - 2018 SalesAgility Ltd.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY SUGARCRM, SUGARCRM DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more
 * details.
 *
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 *
 * You can contact SugarCRM, Inc. headquarters at 10050 North Wolfe Road,
 * SW2-130, Cupertino, CA 95014, USA. or at email address contact@sugarcrm.com.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * SugarCRM" logo and "Supercharged by SuiteCRM" logo. If the display of the logos is not
 * reasonably feasible for technical reasons, the Appropriate Legal Notices must
 * display the words "Powered by SugarCRM" and "Supercharged by SuiteCRM".
 */

/**
 * Created by PhpStorm.
 * User: viocolano
 * Date: 22/06/18
 * Time: 12:33
 */

namespace SuiteCRM\Search\ElasticSearch;

use BeanFactory;
use ParserSearchFields;
use SugarBean;
use SuiteCRM\Utility\BeanJsonSerializer;

class ElasticSearchIndexer
{
    private $indexName = 'main';
    // 70% slower without using search defs
    // but better quality indexing
    private $useSearchDefs = false;
    private $output = false;
    private $batchSize = 1000;
    private $indexedRecords;

    public static function _run($output = false, $useSearchDefs = false)
    {
        $indexer = new self();

        $indexer->output = $output;
        $indexer->useSearchDefs = $useSearchDefs;

        $indexer->run();
    }

    public function run()
    {
        $this->log('@', 'Starting indexing procedures');

        $this->indexedRecords = 0;

        $client = ElasticSearchClientBuilder::getClient();

        if ($this->useSearchDefs) {
            $this->log('@', 'Indexing is performed using Searchdefs');
        } else {
            $this->log('@', 'Indexing is performed using BeanJsonSerialiser');
        }

        try {
            $client->indices()->delete(['index' => '_all']);
        } /** @noinspection PhpRedundantCatchClauseInspection */
        catch (\Elasticsearch\Common\Exceptions\Missing404Exception $ignore) {
            // Index not there, not big deal since we meant to delete it anyway.
            $this->log('*', 'Index not found, no index has been deleted.');
        }

        $start = microtime(true);

        $modules = $this->getModulesToIndex();

        foreach ($modules as $module) {
            $this->indexModule($module, $client);
        }

        $end = microtime(true);

        $elapsed = ($end - $start); // seconds

        $this->log('@', sprintf("Done! Indexed %d modules and %d records in %01.3F s", count($modules), $this->indexedRecords, $elapsed));
        $estimation = $elapsed / $this->indexedRecords * 200000;
        $this->log('@', sprintf("It would take ~%d min for 200,000 records, assuming a linear expansion", $estimation / 60));
    }

    public function log($type, $message)
    {
        if (!$this->output) return;

        switch ($type) {
            case '@':
                $type = "\033[32m$type\033[0m";
                break;
            case '*':
                $type = "\033[33m$type\033[0m";
                break;
            case '!':
                $type = "\033[31m$type\033[0m";
                break;
        }

        echo " [$type] ", $message, PHP_EOL;
    }

    /**
     * @return string[]
     */
    public function getModulesToIndex()
    {
        // TODO
        return ['Accounts', 'Contacts', 'Users'];
    }

    /**
     * @param $module string
     * @param $client \Elasticsearch\Client
     */
    private function indexModule($module, $client)
    {
        $beans = BeanFactory::getBean($module)->get_full_list();

        $this->indexBatch($module, $beans, $client);

        $count = count($beans);
        $this->indexedRecords += $count;
        $this->log('@', sprintf('Indexed %d %s', $count, $module));
    }

    /**
     * @param $module string
     * @param $beans SugarBean[]
     * @param $client \Elasticsearch\Client
     */
    private function indexBatch($module, $beans, $client)
    {
        if ($this->useSearchDefs)
            $fields = $this->getFieldsToIndex($module);

        $params = ['body' => []];

        foreach ($beans as $key => $bean) {

            $params['body'][] = [
                'index' => [
                    '_index' => $this->indexName,
                    '_type' => $module,
                    '_id' => $bean->id
                ]
            ];

            $params['body'][] = $this->makeIndexParamsBodyFromBean($bean, $fields);

            // Send a batch of $this->batchSize elements to the server
            if ($key % $this->batchSize == 0) {
                $responses = $client->bulk($params);

                // erase the old bulk request
                $params = ['body' => []];

                // unset the bulk response when you are done to save memory
                unset($responses);
            }
        }

        // Send the last batch if it exists
        if (!empty($params['body'])) {
            $responses = $client->bulk($params);
            unset($responses);
        }
    }

    /**
     * @param $module string
     * @return string[]
     */
    public function getFieldsToIndex($module)
    {
        require_once 'modules/ModuleBuilder/parsers/parser.searchfields.php';

        $parsers = new ParserSearchFields($module);
        $fields = $parsers->getSearchFields()[$module];

        $parsedFields = [];

        foreach ($fields as $key => $field) {
            if (isset($field['query_type']) && $field['query_type'] != 'default') {
                $this->log('*', "[$module]->$key is not a supported query type!");
                continue;
            };

            if (!empty($field['operator'])) {
                $this->log('*', "[$module]->$key has an operator!");
                continue;
            }

            if (strpos($key, 'range_date') !== false) {
                continue;
            }

            if (!empty($field['db_field'])) {
                foreach ($field['db_field'] as $db_field) {
                    $parsedFields[$key][] = $db_field;
                }
            } else {
                $parsedFields[] = $key;
            }
        }

        return $parsedFields;
    }

    /**
     * Note: it removes not found fields from the `$fields` argument.
     * @param $bean SugarBean
     * @param $fields array
     * @return array
     */
    private function makeIndexParamsBodyFromBean($bean, &$fields)
    {
        if ($this->useSearchDefs) {
            $body = [];

            foreach ($fields as $key => $field) {
                if (is_array($field)) {
                    // TODO Addresses should be structured better
                    foreach ($field as $subfield) {
                        if ($this->hasField($bean, $subfield)) {
                            $body[$key][$subfield] = mb_convert_encoding($bean->$subfield, "UTF-8", "HTML-ENTITIES");
                        }
                    }
                } else {
                    if ($this->hasField($bean, $field)) {
                        $body[$field] = mb_convert_encoding($bean->$field, "UTF-8", "HTML-ENTITIES");
                    }
                }
            }

            return $body;
        } else {
            $values = BeanJsonSerializer::toArray($bean);

            unset($values['id']);

            return $values;
        }
    }

    /**
     * @param $bean
     * @param $field
     * @return bool
     */
    private function hasField($bean, $field)
    {
        if (!isset($bean->$field)) {
            $this->log('!', "{$bean->module_name}->$field does not exist!");

            return false;
        } else {
            return true;
        }
    }

    /**
     * @param $bean SugarBean
     * @param $fields array|null
     * @param $client \Elasticsearch\Client|null
     */
    public function indexBean($bean, $fields = null, $client = null)
    {
        // TODO tests
        if (empty($client)) {
            $client = ElasticSearchClientBuilder::getClient();
        }

        if ($this->useSearchDefs && empty($fields)) {
            $fields = $this->getFieldsToIndex($bean->module_name);
        }

        $args = $this->makeIndexParamsFromBean($bean, $fields);

        $client->index($args);
    }

    /**
     * @param $bean SugarBean
     * @param $fields array
     * @return array
     */
    private function makeIndexParamsFromBean($bean, $fields)
    {
        // TODO tests
        $args = $this->makeParamsHeaderFromBean($bean);
        $args['body'] = $this->makeIndexParamsBodyFromBean($bean, $fields);
        return $args;
    }

    /**
     * @param $bean SugarBean
     * @return array
     */
    private function makeParamsHeaderFromBean($bean)
    {
        // TODO tests
        $args = [
            'index' => $this->indexName,
            'type' => $bean->module_name,
            'id' => $bean->id,
        ];

        return $args;
    }

    /**
     * @param $bean SugarBean
     * @param $client \Elasticsearch\Client|null
     */
    public function removeBean($bean, $client = null)
    {
        // TODO tests
        if (empty($client)) {
            $client = ElasticSearchClientBuilder::getClient();
        }

        $args = $this->makeParamsHeaderFromBean($bean);

        $client->delete($args);
    }

    public function removeIndex($client = null)
    {
        // TODO tests
        if (empty($client)) $client = ElasticSearchClientBuilder::getClient();

        $params = ['index' => $this->indexName];
        $client->indices()->delete($params);
    }
}