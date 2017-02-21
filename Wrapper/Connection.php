<?php

namespace Realestate\MssqlBundle\Wrapper;

class Connection extends \Doctrine\DBAL\Connection {
    /**
     * @param string $query
     * @param array $params
     * @param array $types
     * @return int
     */
    public function executeUpdate($query, array $params = array(), array $types = array()) {
        foreach ($types as $i => $type) {
            if($type === "text" || $type === "string") {
                $query = $this->addPrefixToParameter($query, $i, 'N');
            }
        }
        return parent::executeUpdate($query, $params, $types);
    }

    /**
     * @param $query
     * @param $index
     * @param $prefix
     * @return mixed
     */
    protected function addPrefixToParameter($query, $index, $prefix) {
        $pos = FALSE; $i = 0;
        do {
            $pos = strpos($query, '?', $pos !== FALSE ? $pos + 1 : 0);
        } while($pos !== FALSE && $i < $index);
        if($pos !== FALSE && $i >= 0) {
            $query = substr_replace($query, $prefix, $pos, 0);
        }
        return $query;
    }
}