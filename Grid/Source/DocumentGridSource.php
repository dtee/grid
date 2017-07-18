<?php

namespace Dtc\GridBundle\Grid\Source;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Dtc\GridBundle\Grid\Column\GridColumn;
use Doctrine\ODM\MongoDB\DocumentManager;

class DocumentGridSource extends AbstractGridSource
{
    protected $dm;
    protected $documentName;
    protected $repository;
    protected $findCache;

    public function __construct(DocumentManager $dm, $documentName)
    {
        $this->dm = $dm;
        $this->repository = $dm->getRepository($documentName);
        $this->documentName = $documentName;
    }

    public function autoDiscoverColumns()
    {
        $this->setColumns($this->getReflectionColumns());
    }

    protected function find()
    {
        if ($this->filter) {
            /** @var ClassMetadata $classMetaData */
            $classMetaData = $this->getClassMetadata();
            $classFields = $classMetaData->fieldMappings;

            $validFilters = array_intersect_key($this->filter, $classFields);

            $query = array();
            foreach ($validFilters as $key => $value) {
                if (is_array($value)) {
                    $query[$key] = ['$in' => $value];
                } else {
                    $query[$key] = $value;
                }
            }
            if (!$query) {
                $starFilter = array_intersect_key($this->filter, ['*' => null]);
                if ($starFilter) {
                    $value = current($starFilter);
                    $starQuery = [];
                    foreach (array_keys($classFields) as $key) {
                        $starQuery[] = "u.{$key} like :{$key}";
                        $qb->setParameter($key, $value);
                    }

                    $star = implode(' or ', $starQuery);
                    if ($query) {
                        $qb->andWhere($star);
                    } else {
                        $qb->add('where', $star);
                    }
                }
            }
        }

        $arguments = array($this->filter, $this->orderBy, $this->limit, $this->offset);
        $hashKey = serialize($arguments);

        if (isset($this->findCache[$hashKey])) {
            return $this->findCache[$hashKey];
        }

        return call_user_func_array([$this->repository, 'findBy'], $arguments);
    }

    /**
     * @return ClassMetadata
     */
    public function getClassMetadata()
    {
        $metaFactory = $this->dm->getMetadataFactory();
        $classInfo = $metaFactory->getMetadataFor($this->documentName);

        return $classInfo;
    }

    /**
     * Generate Columns based on document's Metadata.
     */
    public function getReflectionColumns()
    {
        $metaClass = $this->getClassMetadata();

        $columns = array();
        foreach ($metaClass->fieldMappings as $fieldInfo) {
            $field = $fieldInfo['fieldName'];
            if (isset($fieldInfo['options']) && isset($fieldInfo['options']['label'])) {
                $label = $fieldInfo['options']['label'];
            } else {
                $label = $this->fromCamelCase($field);
            }

            $columns[$field] = new GridColumn($field, $label);
        }

        return $columns;
    }

    protected function fromCamelCase($str)
    {
        $func = function ($str) {
            return ' '.$str[0];
        };

        $value = preg_replace_callback('/([A-Z])/', $func, $str);
        $value = ucfirst($value);

        return $value;
    }

    public function getCount()
    {
        return $this->dm->createQueryBuilder($this->documentName)->count()->getQuery()->execute();
    }

    public function getRecords()
    {
        return $this->find();
    }
}
