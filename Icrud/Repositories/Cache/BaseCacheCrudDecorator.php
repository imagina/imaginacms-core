<?php

namespace Modules\Core\Icrud\Repositories\Cache;

use Illuminate\Cache\Repository;

use Modules\Core\Repositories\Cache\BaseCacheDecorator;
use Modules\Core\Icrud\Repositories\BaseCrudRepository;

abstract class BaseCacheCrudDecorator extends BaseCacheDecorator implements BaseCrudRepository
{
  public function getItemsBy($params)
  {

    //Extra validation important to exports.
    if (!empty($params->returnAsQuery)) {
      return $this->repository->getItemsBy($params); // Bypass cache
    }

    $query = $this->repository->getOrCreateQuery($params);

    return $this->remember(function () use ($params) {
      return $this->repository->getItemsBy($params);
    }, $this->createKey($query, $params));
  }

  public function getItem($criteria, $params = false)
  {

    $query = $this->repository->getOrCreateQuery($params, $criteria);

    return $this->remember(function () use ($criteria, $params) {
      return $this->repository->getItem($criteria, $params);
    }, $this->createKey($query, $params));
  }

  public function getItemsByTransformed($models, $params)
  {
    $params->transformed = true;
    $query = $this->repository->getOrCreateQuery($params);

    return $this->remember(function () use ($models, $params) {
      return $this->repository->getItemsByTransformed($models, $params);
    }, $this->createKey($query, $params));
  }

  public function create($data)
  {
    $this->cache->tags($this->getTags())->flush();

    return $this->repository->create($data);
  }

  public function updateBy($criteria, $data, $params = false)
  {
    $this->cache->tags($this->getTags())->flush();

    return $this->repository->updateBy($criteria, $data, $params);
  }

  public function deleteBy($criteria, $params = false)
  {
    $this->cache->tags($this->getTags())->flush();

    return $this->repository->deleteBy($criteria, $params);
  }

  public function restoreBy($criteria, $params = false)
  {
    $this->cache->tags($this->getTags())->flush();

    return $this->repository->restoreBy($criteria, $params);
  }

  public function bulkOrder($data, $params = false)
  {
    $this->cache->tags($this->getTags())->flush();

    return $this->repository->bulkOrder($data, $params);
  }

  public function bulkUpdate($data, $params = false)
  {
    $this->cache->tags($this->getTags())->flush();

    return $this->repository->bulkUpdate($data, $params);
  }

  public function bulkCreate($data)
  {
    $this->cache->tags($this->getTags())->flush();

    return $this->repository->bulkCreate($data);
  }

  public function getDashboard($params)
  {
    return $this->remember(function () use ($params) {
      return $this->repository->getDashboard($params);
    }, $this->createKey(null, $params));
  }

  public function createKey($query, $params)
  {
    $cacheKey = str_replace(["\"", "`", "{", "}"], "", (($query ? $query->toSql() ?? "" : "") .
      (\serialize($query ? $query->getBindings() ?? "" : "")) .
      (!empty($params->filter) ? \serialize($params->filter) : "") .
      (!empty($params->order) ? \serialize($params->order) : "") .
      (!empty($params->include) ? \serialize($params->include) : "") .
      (!empty($params->page) ? \serialize($params->page) : "") .
      (!empty($params->take) ? \serialize($params->take) : "") .
      ($params->transformed ?? false)
    ));

    return hash('sha256', $cacheKey);
  }

  public function getTags()
  {
    return array_merge(is_null($this->tags) ? [] : (is_array($this->tags) ? $this->tags : [$this->tags]), [$this->entityName]);
  }

  public function updateOrCreate($validationData, $data)
  {
    $this->cache->tags($this->getTags())->flush();
    return $this->repository->updateOrCreate($validationData, $data);
  }
}
