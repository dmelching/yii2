<?php
/**
 * ActiveQuery class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db;

use yii\db\Connection;
use yii\db\Command;
use yii\db\QueryBuilder;
use yii\db\Expression;
use yii\db\Exception;

/**
 * ActiveQuery represents a DB query associated with an Active Record class.
 *
 * ActiveQuery instances are usually created by [[ActiveRecord::find()]], [[ActiveRecord::findBySql()]]
 * and [[ActiveRecord::count()]].
 *
 * ActiveQuery mainly provides the following methods to retrieve the query results:
 *
 * - [[one()]]: returns a single record populated with the first row of data.
 * - [[all()]]: returns all records based on the query results.
 * - [[value()]]: returns the value of the first column in the first row of the query result.
 * - [[exists()]]: returns a value indicating whether the query result has data or not.
 *
 * Because ActiveQuery extends from [[Query]], one can use query methods, such as [[where()]],
 * [[orderBy()]] to customize the query options.
 *
 * ActiveQuery also provides the following additional query options:
 *
 * - [[with]]: list of relations that this query should be performed with.
 * - [[indexBy]]: the name of the column by which the query result should be indexed.
 * - [[asArray]]: whether to return each record as an array.
 * - [[scopes]]: list of scopes that should be applied to this query.
 *
 * These options can be configured using methods of the same name. For example:
 *
 * ~~~
 * $customers = Customer::find()->with('orders')->asArray()->all();
 * ~~~
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class ActiveQuery extends Query
{
	/**
	 * @var string the name of the ActiveRecord class.
	 */
	public $modelClass;
	/**
	 * @var array list of relations that this query should be performed with
	 */
	public $with;
	/**
	 * @var string the name of the column by which query results should be indexed by.
	 * This is only used when the query result is returned as an array when calling [[all()]].
	 */
	public $indexBy;
	/**
	 * @var boolean whether to return each record as an array. If false (default), an object
	 * of [[modelClass]] will be created to represent each record.
	 */
	public $asArray;
	/**
	 * @var array list of scopes that should be applied to this query
	 */
	public $scopes;
	/**
	 * @var string the SQL statement to be executed for retrieving AR records.
	 * This is set by [[ActiveRecord::findBySql()]].
	 */
	public $sql;

	/**
	 * PHP magic method.
	 * This method is overridden so that scope methods declared in [[modelClass]]
	 * can be invoked as methods of ActiveQuery.
	 * @param string $name
	 * @param array $params
	 * @return mixed|ActiveQuery
	 */
	public function __call($name, $params)
	{
		if (method_exists($this->modelClass, $name)) {
			$this->scopes[$name] = $params;
			return $this;
		} else {
			return parent::__call($name, $params);
		}
	}

	/**
	 * Executes query and returns all results as an array.
	 * @return array the query results. If the query results in nothing, an empty array will be returned.
	 */
	public function all()
	{
		$command = $this->createCommand();
		$rows = $command->queryAll();
		if ($rows !== array()) {
			$models = $this->createModels($rows);
			if (!empty($this->with)) {
				$this->populateRelations($models, $this->with);
			}
			return $models;
		} else {
			return array();
		}
	}

	/**
	 * Executes query and returns a single row of result.
	 * @return ActiveRecord|array|null a single row of query result. Depending on the setting of [[asArray]],
	 * the query result may be either an array or an ActiveRecord object. Null will be returned
	 * if the query results in nothing.
	 */
	public function one()
	{
		$command = $this->createCommand();
		$row = $command->queryRow();
		if ($row !== false && !$this->asArray) {
			/** @var $class ActiveRecord */
			$class = $this->modelClass;
			$model = $class::create($row);
			if (!empty($this->with)) {
				$models = array($model);
				$this->populateRelations($models, $this->with);
				$model = $models[0];
			}
			return $model;
		} else {
			return $row === false ? null : $row;
		}
	}

	/**
	 * Returns the query result as a scalar value.
	 * The value returned will be the first column in the first row of the query results.
	 * @return string|boolean the value of the first column in the first row of the query result.
	 * False is returned if the query result is empty.
	 */
	public function value()
	{
		return $this->createCommand()->queryScalar();
	}

	/**
	 * Returns a value indicating whether the query result contains any row of data.
	 * @return boolean whether the query result contains any row of data.
	 */
	public function exists()
	{
		$this->select = array(new Expression('1'));
		return $this->value() !== false;
	}

	/**
	 * Creates a DB command that can be used to execute this query.
	 * @param Connection $db the DB connection used to create the DB command.
	 * If null, the DB connection returned by [[modelClass]] will be used.
	 * @return Command the created DB command instance.
	 */
	public function createCommand($db = null)
	{
		/** @var $modelClass ActiveRecord */
		$modelClass = $this->modelClass;
		if ($db === null) {
			$db = $modelClass::getDbConnection();
		}
		if ($this->sql === null) {
			if ($this->from === null) {
				$tableName = $modelClass::tableName();
				$this->from = array($tableName);
			}
			if (!empty($this->scopes)) {
				$this->applyScopes($this->scopes);
			}
			/** @var $qb QueryBuilder */
			$qb = $db->getQueryBuilder();
			$this->sql = $qb->build($this);
		}
		return $db->createCommand($this->sql, $this->params);
	}

	/**
	 * Sets the [[asArray]] property.
	 * @param boolean $value whether to return the query results in terms of arrays instead of Active Records.
	 * @return ActiveQuery the query object itself
	 */
	public function asArray($value = true)
	{
		$this->asArray = $value;
		return $this;
	}

	/**
	 * Specifies the relations with which this query should be performed.
	 *
	 * The parameters to this method can be either one or multiple strings, or a single array
	 * of relation names and the optional callbacks to customize the relations.
	 *
	 * The followings are some usage examples:
	 *
	 * ~~~
	 * // find customers together with their orders and country
	 * Customer::find()->with('orders', 'country')->all();
	 * // find customers together with their country and orders of status 1
	 * Customer::find()->with(array(
	 *     'orders' => function($query) {
	 *         $query->andWhere('status = 1');
	 *     },
	 *     'country',
	 * ))->all();
	 * ~~~
	 *
	 * @return ActiveQuery the query object itself
	 */
	public function with()
	{
		$this->with = func_get_args();
		if (isset($this->with[0]) && is_array($this->with[0])) {
			// the parameter is given as an array
			$this->with = $this->with[0];
		}
		return $this;
	}

	/**
	 * Sets the [[indexBy]] property.
	 * @param string $column the name of the column by which the query results should be indexed by.
	 * @return ActiveQuery the query object itself
	 */
	public function indexBy($column)
	{
		$this->indexBy = $column;
		return $this;
	}

	/**
	 * Specifies the scopes to be applied to this query.
	 *
	 * The parameters to this method can be either one or multiple strings, or a single array
	 * of scopes names and their corresponding customization parameters.
	 *
	 * The followings are some usage examples:
	 *
	 * ~~~
	 * // find all active customers
	 * Customer::find()->scopes('active')->all();
	 * // find active customers whose age is greater than 30
	 * Customer::find()->scopes(array(
	 *     'active',
	 *     'olderThan' => array(30),
	 * ))->all();
	 * // alternatively the above statement can be written as:
	 * Customer::find()->active()->olderThan(30)->all();
	 * ~~~
	 * @return ActiveQuery the query object itself
	 */
	public function scopes()
	{
		$this->scopes = func_get_args();
		if (isset($this->scopes[0]) && is_array($this->scopes[0])) {
			// the parameter is given as an array
			$this->scopes = $this->scopes[0];
		}
		return $this;
	}

	private function createModels($rows)
	{
		$models = array();
		if ($this->asArray) {
			if ($this->indexBy === null) {
				return $rows;
			}
			foreach ($rows as $row) {
				$models[$row[$this->indexBy]] = $row;
			}
		} else {
			/** @var $class ActiveRecord */
			$class = $this->modelClass;
			if ($this->indexBy === null) {
				foreach ($rows as $row) {
					$models[] = $class::create($row);
				}
			} else {
				foreach ($rows as $row) {
					$model = $class::create($row);
					$models[$model->{$this->indexBy}] = $model;
				}
			}
		}
		return $models;
	}

	private function populateRelations(&$models, $with)
	{
		$primaryModel = new $this->modelClass;
		$relations = $this->normalizeRelations($primaryModel, $with);
		foreach ($relations as $name => $relation) {
			if ($relation->asArray === null) {
				// inherit asArray from primary query
				$relation->asArray = $this->asArray;
			}
			$relation->findWith($name, $models);
		}
	}

	/**
	 * @param ActiveRecord $model
	 * @param array $with
	 * @return ActiveRelation[]
	 */
	private function normalizeRelations($model, $with)
	{
		$relations = array();
		foreach ($with as $name => $callback) {
			if (is_integer($name)) {
				$name = $callback;
				$callback = null;
			}
			if (($pos = strpos($name, '.')) !== false) {
				// with sub-relations
				$childName = substr($name, $pos + 1);
				$name = substr($name, 0, $pos);
			} else {
				$childName = null;
			}

			$t = strtolower($name);
			if (!isset($relations[$t])) {
				$relation = $model->getRelation($name);
				$relation->primaryModel = null;
				$relations[$t] = $relation;
			} else {
				$relation = $relations[$t];
			}

			if (isset($childName)) {
				$relation->with[$childName] = $callback;
			} elseif ($callback !== null) {
				call_user_func($callback, $relation);
			}
		}
		return $relations;
	}

	private function applyScopes($scopes)
	{
		$modelClass = $this->modelClass;
		foreach ($scopes as $name => $config) {
			if (is_integer($name)) {
				$modelClass::$config($this);
			} else {
				array_unshift($config, $this);
				call_user_func_array(array($modelClass, $name), $config);
			}
		}
	}
}