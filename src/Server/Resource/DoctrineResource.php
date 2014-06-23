<?php

namespace ZF\Apigility\Doctrine\Server\Resource;

use DoctrineModule\Persistence\ObjectManagerAwareInterface;
use DoctrineModule\Persistence\ProvidesObjectManager;
use DoctrineModule\Stdlib\Hydrator;
use ZF\Apigility\Doctrine\Server\Collection\Query;
use Zend\Stdlib\Hydrator\HydratorInterface;
use ZF\Apigility\Doctrine\Server\Resource\Query\FetchOrmQuery;
use ZF\ApiProblem\ApiProblem;
use ZF\Rest\AbstractResourceListener;
use ZF\Hal\Collection;
use Zend\EventManager\StaticEventManager;
use ZF\Apigility\Doctrine\Server\Hydrator\Strategy\CollectionExtract;
use Zend\ServiceManager\ServiceManager;
use Zend\ServiceManager\ServiceManagerAwareInterface;
use Zend\Stdlib\ArrayUtils;

/**
 * Class DoctrineResource
 *
 * @package ZF\Apigility\Doctrine\Server\Resource
 */
class DoctrineResource extends AbstractResourceListener
    implements ObjectManagerAwareInterface, ServiceManagerAwareInterface
{
    use ProvidesObjectManager;
    
    /**
     * @var ServiceManager 
     */
    protected $serviceManager;

    /**
     * @var Query\ApigilityFetchAllQuery
     */
    protected $fetchAllQuery;

    public function setServiceManager(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;

        return $this;
    }

    /**
     * @return ServiceManager
     */
    public function getServiceManager()
    {
        return $this->serviceManager;
    }

    /**
     * @param \ZF\Apigility\Doctrine\Server\Collection\Query\ApigilityFetchAllQuery $fetchAllQuery
     */
    public function setFetchAllQuery($fetchAllQuery)
    {
        $this->fetchAllQuery = $fetchAllQuery;
    }

    /**
     * @return \ZF\Apigility\Doctrine\Server\Collection\Query\ApigilityFetchAllQuery
     */
    public function getFetchAllQuery()
    {
        return $this->fetchAllQuery;
    }

    /**
     * @var HydratorInterface
     */
    protected $hydrator;

    /**
     * @param \Zend\Stdlib\Hydrator\HydratorInterface $hydrator
     */
    public function setHydrator($hydrator)
    {
        $this->hydrator = $hydrator;
    }

    /**
     * @return \Zend\Stdlib\Hydrator\HydratorInterface
     */
    public function getHydrator()
    {
        if (!$this->hydrator) {
            // @codeCoverageIgnoreStart
            // FIXME: find a way to test this line from a created API.  Shouldn't all created API's have a hydrator?
            $this->hydrator = new Hydrator\DoctrineObject($this->getObjectManager(), $this->getEntityClass());
        }
            // @codeCoverageIgnoreEnd
        return $this->hydrator;
    }

    /**
     * Create a resource
     *
     * @param  mixed            $data
     * @return ApiProblem|mixed
     */
    public function create($data)
    {
        $entityClass = $this->getEntityClass();
        $entity = new $entityClass;
        $hydrator = $this->getHydrator();
        $hydrator->hydrate((array) $data, $entity);

        $this->getObjectManager()->persist($entity);
        $this->getObjectManager()->flush();

        return $entity;
    }

    /**
     * Delete a resource
     *
     * @param  mixed            $id
     * @return ApiProblem|mixed
     */
    public function delete($id)
    {
        $entity = $this->getObjectManager()->find($this->getEntityClass(), $id);
        if (!$entity) {
            // @codeCoverageIgnoreStart
            return new ApiProblem(404, 'Entity with id ' . $id . ' was not found');
        }
            // @codeCoverageIgnoreEnd

        $this->getObjectManager()->remove($entity);
        $this->getObjectManager()->flush();

        return true;
    }

    /**
     * Delete a collection, or members of a collection
     *
     * @param  mixed            $data
     * @return ApiProblem|mixed
     *                               @codeCoverageIgnore
     */
    public function deleteList($data)
    {
        return new ApiProblem(405, 'The DELETE method has not been defined for collections');
    }

    /**
     * Fetch a resource
     *
     * If the extractCollections array contains a collection for this resource
     * expand that collection instead of returning a link to the collection
     *
     * @param  mixed            $id
     * @return ApiProblem|mixed
     */
    public function fetch($id)
    {
        $objectManager = $this->getObjectManager();

        if (class_exists('\\Doctrine\\ORM\\EntityManager') && $objectManager instanceof \Doctrine\ORM\EntityManager) {
            $parameters = $this->getEvent()->getQueryParams()->toArray();

            $fetchQuery = new FetchOrmQuery();
            $fetchQuery->setServiceLocator($this->getServiceManager());
            $fetchQuery->setObjectManager($objectManager);

            $query = $fetchQuery->createQuery($this->getEntityClass(), $id, $parameters);

            return $query->getSingleResult();
        } else {
            return $this->getObjectManager()->find($this->getEntityClass(), $id);
        }
    }

    /**
     * Fetch all or a subset of resources
     *
     *
     * @see Apigility/Doctrine/Server/Resource/AbstractResource.php
     * @param  array            $params
     * @return ApiProblem|mixed
     */
    public function fetchAll($data = array())
    {
        $objectManager = $this->getObjectManager();

        // Build query
        $fetchAllQuery = $this->getFetchAllQuery();
        $queryBuilder = $fetchAllQuery->createQuery($this->getEntityClass(), $data);

        if ($queryBuilder instanceof ApiProblem) {
            // @codeCoverageIgnoreStart
            return $queryBuilder;
        }
            // @codeCoverageIgnoreEnd

        $adapter = $fetchAllQuery->getPaginatedQuery($queryBuilder);
        $reflection = new \ReflectionClass($this->getCollectionClass());
        $collection = $reflection->newInstance($adapter);

        // Add event to set extra HAL data
        $entityClass = $this->getEntityClass();
        StaticEventManager::getInstance()->attach('ZF\Rest\RestController', 'getList.post',
            function ($e) use ($fetchAllQuery, $entityClass, $data) {
                $halCollection = $e->getParam('collection');
                $halCollection->getCollection()->setItemCountPerPage($halCollection->getPageSize());
                $halCollection->getCollection()->setCurrentPageNumber($halCollection->getPage());

                $halCollection->setAttributes(array(
                   'count' => $halCollection->getCollection()->getCurrentItemCount(),
                   'total' => $halCollection->getCollection()->getTotalItemCount(),
                   'collectionTotal' => $fetchAllQuery->getCollectionTotal($entityClass),
                ));

                $halCollection->setCollectionRouteOptions(array(
                    'query' => ArrayUtils::iteratorToArray($data)
                ));
            }
        );

        return $collection;
    }

    /**
     * Patch (partial in-place update) a resource
     *
     * @param  mixed            $id
     * @param  mixed            $data
     * @return ApiProblem|mixed
     */
    public function patch($id, $data)
    {
        $entity = $this->getObjectManager()->find($this->getEntityClass(), $id);
        if (!$entity) {
            // @codeCoverageIgnoreStart
            return new ApiProblem(404, 'Entity with id ' . $id . ' was not found');
        }
            // @codeCoverageIgnoreEnd

        // Load full data:
        $hydrator = $this->getHydrator();
        $originalData = $hydrator->extract($entity);
        $patchedData = array_merge($originalData, (array) $data);

        // Hydrate entity
        $hydrator->hydrate($patchedData, $entity);
        $this->getObjectManager()->flush();

        return $entity;
    }

    /**
     * Replace a collection or members of a collection
     *
     * @param  mixed            $data
     * @return ApiProblem|mixed
     *                               @codeCoverageIgnore
     */
    public function replaceList($data)
    {
        return new ApiProblem(405, 'The PUT method has not been defined for collections');
    }

    /**
     * Update a resource
     *
     * @param  mixed            $id
     * @param  mixed            $data
     * @return ApiProblem|mixed
     */
    public function update($id, $data)
    {
        $entity = $this->getObjectManager()->find($this->getEntityClass(), $id);
        if (!$entity) {
            // @codeCoverageIgnoreStart
            return new ApiProblem(404, 'Entity with id ' . $id . ' was not found');
            // @codeCoverageIgnoreEnd
        }

        $hydrator = $this->getHydrator();
        $hydrator->hydrate((array) $data, $entity);
        $this->getObjectManager()->flush();

        return $entity;
    }

}
