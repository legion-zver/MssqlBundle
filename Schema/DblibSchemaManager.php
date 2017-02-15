<?php

namespace Realestate\MssqlBundle\Schema;

use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\SQLServerSchemaManager;

/**
 * Schema manager for the MsSql/Dblib RDBMS.
 *
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @author      Scott Morken <scott.morken@pcmail.maricopa.edu>
 * @author      Konsta Vesterinen <kvesteri@cc.hut.fi>
 * @author      Lukas Smith <smith@pooteeweet.org> (PEAR MDB2 library)
 * @author      Roman Borschel <roman@code-factory.org>
 * @author      Benjamin Eberlei <kontakt@beberlei.de>
 * @version     $Revision$
 * @since       2.0
 */

class DblibSchemaManager extends SQLServerSchemaManager
{
    protected function _getPortableSequenceDefinition($sequence) {
        return end($sequence);
    }

    public function createDatabase($name)
    {
        $query = "CREATE DATABASE $name";
        if ($this->_conn->options['database_device']) {
            $query.= ' ON '.$this->_conn->options['database_device'];
            $query.= $this->_conn->options['database_size'] ? '=' .
                     $this->_conn->options['database_size'] : '';
        }
        return $this->_conn->standaloneQuery($query, null, true);
    }
    
    /**
     * lists all database sequences
     *
     * @param string|null $database
     * @return array
     */
    public function listSequences($database = null)
    {
        $query = "SELECT name FROM sysobjects WHERE xtype = 'U'";
        $tableNames = $this->_conn->fetchAll($query);

        return array_map(array($this->_conn->formatter, 'fixSequenceName'), $tableNames);
    }
   
    /**
     * lists table views
     *
     * @param string $table     database table name
     * @return array
     */
    public function listTableViews($table)
    {
        $keyName = 'INDEX_NAME';
        $pkName = 'PK_NAME';
        if ($this->_conn->getAttribute(Doctrine::ATTR_PORTABILITY) & Doctrine::PORTABILITY_FIX_CASE) {
            if ($this->_conn->getAttribute(Doctrine::ATTR_FIELD_CASE) == CASE_LOWER) {
                $keyName = strtolower($keyName);
                $pkName  = strtolower($pkName);
            } else {
                $keyName = strtoupper($keyName);
                $pkName  = strtoupper($pkName);
            }
        }
        $table = $this->_conn->quote($table, 'text');
        $query = 'EXEC sp_statistics @table_name = ' . $table;
        $indexes = $this->_conn->fetchColumn($query, $keyName);

        $query = 'EXEC sp_pkeys @table_name = ' . $table;
        $pkAll = $this->_conn->fetchColumn($query, $pkName);

        $result = array();

        foreach ($indexes as $index) {
            if ( ! in_array($index, $pkAll) && $index != null) {
                $result[] = $this->_conn->formatter->fixIndexName($index);
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff)
    {
        $queryParts  = array();
        $sql         = array();
        $columnSql   = array();
        $commentsSql = array();

        /** @var \Doctrine\DBAL\Schema\Column $column */
        foreach ($diff->addedColumns as $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $columnDef = $column->toArray();
            $queryParts[] = 'ADD ' . $this->getColumnDeclarationSQL($column->getQuotedName($this), $columnDef);

            //TODO: Сделать поддержку конфигурирования
            //if (isset($columnDef['default'])) {
                //$queryParts[] = $this->getAlterTableAddDefaultConstraintClause($diff->name, $column);
            //}

            $comment = $this->getColumnComment($column);

            if ( ! empty($comment) || is_numeric($comment)) {
                $commentsSql[] = $this->getCreateColumnCommentSQL(
                    $diff->name,
                    $column->getQuotedName($this),
                    $comment
                );
            }
        }

        foreach ($diff->removedColumns as $column) {
            if ($this->onSchemaAlterTableRemoveColumn($column, $diff, $columnSql)) {
                continue;
            }
            /** TODO: Внедрить:
            SELECT
            default_constraints.name
            FROM
            sys.all_columns

            INNER JOIN
            sys.tables
            ON all_columns.object_id = tables.object_id

            INNER JOIN
            sys.schemas
            ON tables.schema_id = schemas.schema_id

            INNER JOIN
            sys.default_constraints
            ON all_columns.default_object_id = default_constraints.object_id

            WHERE
            schemas.name = 'dbo'
            AND tables.name = 'tablename'
            AND all_columns.name = 'columnname'
             */

            $queryParts[] = 'DROP COLUMN ' . $column->getQuotedName($this);
        }

        /* @var $columnDiff \Doctrine\DBAL\Schema\ColumnDiff */
        foreach ($diff->changedColumns as $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            $column     = $columnDiff->column;
            $comment    = $this->getColumnComment($column);
            $hasComment = ! empty ($comment) || is_numeric($comment);

            if ($columnDiff->fromColumn instanceof Column) {
                $fromComment    = $this->getColumnComment($columnDiff->fromColumn);
                $hasFromComment = ! empty ($fromComment) || is_numeric($fromComment);

                if ($hasFromComment && $hasComment && $fromComment != $comment) {
                    $commentsSql[] = $this->getAlterColumnCommentSQL(
                        $diff->name,
                        $column->getQuotedName($this),
                        $comment
                    );
                } elseif ($hasFromComment && ! $hasComment) {
                    $commentsSql[] = $this->getDropColumnCommentSQL($diff->name, $column->getQuotedName($this));
                } elseif ($hasComment) {
                    $commentsSql[] = $this->getCreateColumnCommentSQL(
                        $diff->name,
                        $column->getQuotedName($this),
                        $comment
                    );
                }
            } else {
                // todo: Original comment cannot be determined. What to do? Add, update, drop or skip?
            }

            // Do not add query part if only comment has changed.
            if ($columnDiff->hasChanged('comment') && count($columnDiff->changedProperties) === 1) {
                continue;
            }

            $requireDropDefaultConstraint = $this->alterColumnRequiresDropDefaultConstraint($columnDiff);

            if ($requireDropDefaultConstraint) {
                $queryParts[] = $this->getAlterTableDropDefaultConstraintClause(
                    $diff->name,
                    $columnDiff->oldColumnName
                );
            }

            $columnDef = $column->toArray();

            $queryParts[] = 'ALTER COLUMN ' .
                $this->getColumnDeclarationSQL($column->getQuotedName($this), $columnDef);

            if (isset($columnDef['default']) && ($requireDropDefaultConstraint || $columnDiff->hasChanged('default'))) {
                $queryParts[] = $this->getAlterTableAddDefaultConstraintClause($diff->name, $column);
            }
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = new Identifier($oldColumnName);

            $sql[] = "sp_RENAME '" .
                $diff->getName($this)->getQuotedName($this) . "." . $oldColumnName->getQuotedName($this) .
                "', '" . $column->getQuotedName($this) . "', 'COLUMN'";

            // Recreate default constraint with new column name if necessary (for future reference).
            if ($column->getDefault() !== null) {
                $queryParts[] = $this->getAlterTableDropDefaultConstraintClause(
                    $diff->name,
                    $oldColumnName->getQuotedName($this)
                );
                $queryParts[] = $this->getAlterTableAddDefaultConstraintClause($diff->name, $column);
            }
        }

        $tableSql = array();

        if ($this->onSchemaAlterTable($diff, $tableSql)) {
            return array_merge($tableSql, $columnSql);
        }

        foreach ($queryParts as $query) {
            $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getQuotedName($this) . ' ' . $query;
        }

        $sql = array_merge($sql, $commentsSql);

        if ($diff->newName !== false) {
            $sql[] = "sp_RENAME '" . $diff->getName($this)->getQuotedName($this) . "', '" . $diff->getNewName()->getName() . "'";

            /**
             * Rename table's default constraints names
             * to match the new table name.
             * This is necessary to ensure that the default
             * constraints can be referenced in future table
             * alterations as the table name is encoded in
             * default constraints' names.
             */
            $sql[] = "DECLARE @sql NVARCHAR(MAX) = N''; " .
                "SELECT @sql += N'EXEC sp_rename N''' + dc.name + ''', N''' " .
                "+ REPLACE(dc.name, '" . $this->generateIdentifierName($diff->name) . "', " .
                "'" . $this->generateIdentifierName($diff->newName) . "') + ''', ''OBJECT'';' " .
                "FROM sys.default_constraints dc " .
                "JOIN sys.tables tbl ON dc.parent_object_id = tbl.object_id " .
                "WHERE tbl.name = '" . $diff->getNewName()->getName() . "';" .
                "EXEC sp_executesql @sql";
        }

        $sql = array_merge(
            $this->getPreAlterTableIndexForeignKeySQL($diff),
            $sql,
            $this->getPostAlterTableIndexForeignKeySQL($diff)
        );

        return array_merge($sql, $tableSql, $columnSql);
    }
}
