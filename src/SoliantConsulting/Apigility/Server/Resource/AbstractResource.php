<?php
namespace SoliantConsulting\Apigility\Server\Resource;

use ZF\ApiProblem\ApiProblem;
use ZF\Rest\AbstractResourceListener;
use Zend\ServiceManager\ServiceManagerAwareInterface;
use Zend\ServiceManager\ServiceManager as ZendServiceManager;
use Doctrine\Common\Persistence\ObjectManager;

class AbstractResource extends AbstractResourceListener implements ServiceManagerAwareInterface
{
    protected $serviceManager;
    protected $objectManager;
    protected $objectManagerAlias;

    public function setServiceManager(ZendServiceManager $serviceManager) {
        $this->serviceManager = $serviceManager;
        return $this;
    }

    public function getServiceManager() {
        return $this->serviceManager;
    }

    public function setObjectManager(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
        return $this;
    }

    public function getObjectManagerAlias()
    {
        return $this->objectManagerAlias;
    }

    public function setObjectManagerAlias($value)
    {
        $this->objectManagerAlias = $value;
        return $this;
    }

    public function getObjectManager()
    {
        if (!$this->objectManager) {
            $this->setObjectManager($this->getServiceManager()->get($this->getObjectManagerAlias()));
        }

        return $this->objectManager;
    }

    /**
     * Create a resource
     *
     * @param  mixed $data
     * @return ApiProblem|mixed
     */
    public function create($data)
    {
        $entity = new $this->getEntityClass();
        $entity->exchangeArray($this->populateReferences((array)$data));

        $this->getObjectManager()->persist($entity);
        $this->getObjectManager()->flush();

        return $entity;
    }

    /**
     * Delete a resource
     *
     * @param  mixed $id
     * @return ApiProblem|mixed
     */
    public function delete($id)
    {
        $entity = $this->getObjectManager()->find($this->getEntityClass(), $id);
        if (!$entity) {
            return new ApiProblem(404, 'Entity with id ' . $id . ' was not found');
        }

        if ($entity->canDelete()) {
            $this->getObjectManager()->remove($entity);
            $this->getObjectManager()->flush();

            return true;
        }

        return new ApiProblem(403, 'Cannot delete entity with id ' . $id);
    }

    /**
     * Delete a collection, or members of a collection
     *
     * @param  mixed $data
     * @return ApiProblem|mixed
     */
    public function deleteList($data)
    {
        return new ApiProblem(405, 'The DELETE method has not been defined for collections');
    }

    /**
     * Fetch a resource
     *
     * @param  mixed $id
     * @return ApiProblem|mixed
     */
    public function fetch($id)
    {
        return $this->getObjectManager()->find($this->getEntityClass(), $id);
    }

    /**
     * Fetch all or a subset of resources
     *
     * @param  array $params
     * @return ApiProblem|mixed
     */
    public function fetchAll($params = array())
    {
        $queryBuilder = $this->getObjectManager()->createQueryBuilder();

        $queryBuilder->select('row')
            ->from($this->getEntityClass(), 'row');

        $parameters = $this->getEvent()->getQueryParams();

        // Defaults
        if (!isset($parameters['_page'])) {
            $parameters['_page'] = 0;
        }
        if (!isset($parameters['_limit'])) {
            $parameters['_limit'] = 25;
        }
        if ($parameters['_limit'] > 100) {
            $parameters['_limit'] = 100;
        }

        // Limits
        $queryBuilder->setFirstResult($parameters['_page'] * $parameters['_limit']);
        $queryBuilder->setMaxResults($parameters['_limit']);

        // Orderby
        if (!isset($parameters['_orderBy'])) {
            $parameters['_orderBy'] = array('id' => 'asc');
        }
        foreach($parameters['_orderBy'] as $fieldName => $sort) {
            $queryBuilder->addOrderBy("row.$fieldName", $sort);
        }

        unset($parameters['_limit'], $parameters['_page'], $parameters['_orderBy']);

        /*
        echo http_build_query(
            array(
                'query' => array(
                    array('field' => '_DatasetID','type' => 'eq' , 'value' => 1),
                    array('field' =>'Cycle_number','type'=>'between', 'from' => 10, 'to'=>100),
                    array('field'=>'Cycle_number', 'type' => 'decimation', 'value' => 10)
                ),
                '_orderBy' => array('columnOne' => 'ASC', 'columnTwo' => 'DESC')));

`       */

        // Add variable parameters
        foreach ($parameters as $key => $value) {
            if ($key == 'query') {
                foreach ($value as $option) {
                    switch ($option['type']) {
                        case 'between':
                            $queryBuilder->andWhere($queryBuilder->expr()->between('row.'.$option['field'], $option['from'], $option['to']));
                            break;

                        case 'eq':
                            $queryBuilder->andWhere($queryBuilder->expr()->eq('row.'.$option['field'] , $option['value']));
                            break;

                        case 'decimation':
                            $md5 = 'a'.md5(uniqid());
                            //$queryBuilder->andWhere("mod(:$md5, row.". $option['field'].")= 0")
                            $queryBuilder->andWhere("mod( row.". $option['field'].", :$md5)= 0")
                                         ->setParameter($md5, $option['value']);
                    }
                }

            }

            else {
                $queryBuilder->andWhere("row.$key = :param_$key");
                $queryBuilder->setParameter("param_$key", $value);
            }
        }

        //print_r($queryBuilder->getDql());
        //die();
        $collectionClass = $this->getCollectionClass();

        return new $collectionClass($queryBuilder->getQuery(), false);
    }

    /**
     * Patch (partial in-place update) a resource
     *
     * @param  mixed $id
     * @param  mixed $data
     * @return ApiProblem|mixed
     */
    public function patch($id, $data)
    {
        $entity = $this->getObjectManager()->find($this->getEntityClass(), $id);
        if (!$entity) {
            return new ApiProblem(404, 'Entity with id ' . $id . ' was not found');
        }

        $data = $this->populateReferences($data);

        $entity->exchangeArray(array_merge($entity->getArrayCopy(), (array)$data));
        $this->getObjectManager()->flush();

        return $entity;
    }

    /**
     * Replace a collection or members of a collection
     *
     * @param  mixed $data
     * @return ApiProblem|mixed
     */
    public function replaceList($data)
    {
        return new ApiProblem(405, 'The PUT method has not been defined for collections');
    }

    /**
     * Update a resource
     *
     * @param  mixed $id
     * @param  mixed $data
     * @return ApiProblem|mixed
     */
    public function update($id, $data)
    {
        $entity = $this->getObjectManager()->find($this->getEntityClass(), $id);
        if (!$entity) {
            return new ApiProblem(404, 'Entity with id ' . $id . ' was not found');
        }

        $entity->exchangeArray($this->populateReferences((array)$data));
        $this->getObjectManager()->flush();

        return $entity;
    }

    private function populateReferences($data)
    {
        $metadataFactory = $this->getObjectManager()->getMetadataFactory();
        $entityMetadata = $metadataFactory->getMetadataFor($this->getEntityClass());

        foreach($entityMetadata->getAssociationMappings() as $map) {
            switch($map['type']) {
                case 2:
                    $data[$map['fieldName']] = $this->getObjectManager()->find($map['targetEntity'], $data[$map['fieldName']]);
                    break;
                default:
                    break;
            }
        }

        return $data;
    }
}
