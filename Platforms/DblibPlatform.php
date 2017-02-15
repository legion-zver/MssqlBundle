<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Realestate\MssqlBundle\Platforms;

use Doctrine\DBAL\DBALException,
    Doctrine\DBAL\Schema\TableDiff;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDef;
use Doctrine\DBAL\Schema\Identifier;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLServer2008Platform;

/**
 * The DblibPlatform provides the behavior, features and SQL dialect of the
 * MsSQL database platform.
 */
class DblibPlatform extends SQLServer2008Platform
{

    /**
     * Whether the platform supports transactions.
     *
     * @return boolean
     */
    public function supportsTransactions()
    {
        return false;
    }

    /**
     * Whether the platform supports savepoints.
     *
     * @return boolean
     */
    public function supportsSavepoints()
    {
        return false;
    }
    

    /**
     * Adds an adapter-specific LIMIT clause to the SELECT statement.
     *
     * @param string $query
     * @param mixed $limit
     * @param mixed $offset
     * @link http://lists.bestpractical.com/pipermail/rt-devel/2005-June/007339.html
     * @return string
     */
    protected function doModifyLimitQuery($query, $limit, $offset = null)
    {
        if ($limit > 0) {
            $count = intval($limit);
            $offset = intval($offset);

            if ($offset < 0) {
                throw new DBALException("LIMIT argument offset=$offset is not valid");
            }

            if ($offset == 0) {
                // SELECT TOP DISTINCT does not work with mssql
                if(preg_match('#^SELECT\s+DISTINCT#i', $query) > 0) {
                    $query = preg_replace('/^SELECT\s+DISTINCT\s/i', 'SELECT DISTINCT TOP ' . $count . ' ', $query);
                } else {
                    $query = preg_replace('/^SELECT\s/i', 'SELECT TOP ' . $count . ' ', $query);
                }

                
            } else {
                $orderby = stristr($query, 'ORDER BY');

                if (!$orderby) {
                    $over = 'ORDER BY (SELECT 0)';
                } else {
                    $over = preg_replace('/\"[^,]*\".\"([^,]*)\"/i', '"inner_tbl"."$1"', $orderby);
                }

                // Remove ORDER BY clause from $query
                $query = preg_replace('/\s+ORDER BY(.*)/', '', $query);

                // Add ORDER BY clause as an argument for ROW_NUMBER()
                //$query = "SELECT ROW_NUMBER() OVER ($over) AS \"doctrine_rownum\", * FROM ($query) AS inner_tbl";
                $query = preg_replace('/^SELECT\s/', '', $query);

                $start = $offset + 1;
                $end = $offset + $count;

                //$query = "SELECT * FROM (SELECT ROW_NUMBER() OVER ($over) AS \"doctrine_rownum\", $query) AS doctrine_tbl WHERE doctrine_rownum BETWEEN $start AND $end";
                
                // distinct x must be first in the select list - didn't work with above
                list($select_list, $from_part) = explode('FROM', $query, 2);
                $query = "SELECT * FROM (SELECT $select_list, ROW_NUMBER() OVER ($over) AS \"doctrine_rownum\" FROM $from_part) AS doctrine_tbl WHERE doctrine_rownum BETWEEN $start AND $end";
                
            }
        }
        
        return $query;
    }


    /**
     * Get the platform name for this instance
     *
     * @return string
     */
    public function getName()
    {
        return 'mssql';
    }


    /**
    /**
     * @override
     */
    protected function initializeDoctrineTypeMappings()
    {
        parent::initializeDoctrineTypeMappings();

        // add uniqueidentifier
        $this->doctrineTypeMapping['uniqueidentifier'] = 'uniqueidentifier';
        // use the geography type
        $this->doctrineTypeMapping['geography'] = 'geography';
        // define this column type as a string so it works properly for now
        $this->doctrineTypeMapping['hierarchyid'] = 'string';
    }

    /**
     * @override
     */
    public function getDateTimeFormatString()
    {
        return 'Y-m-d H:i:s.u';
    }
    
    /**
     * {@inheritDoc}
     */
    public function supportsSchemas()
    {
        return true;
    }    
    
    /**
     * {@inheritDoc}
     */
    public function canEmulateSchemas()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Modifies column declaration order as it differs in Microsoft SQL Server.
     */
    public function getColumnDeclarationSQL($name, array $field) {
        if (isset($field['columnDefinition'])) {
            $columnDef = $this->getCustomTypeDeclarationSQL($field);
        } else {
            $default = $this->getDefaultValueDeclarationSQL($field);

            $collation = (isset($field['collation']) && $field['collation']) ?
                ' ' . $this->getColumnCollationDeclarationSQL($field['collation']) : '';

            $notnull = (isset($field['notnull']) && $field['notnull']) ? ' NOT NULL' : '';

            $unique = (isset($field['unique']) && $field['unique']) ?
                ' ' . $this->getUniqueFieldDeclarationSQL() : '';

            $check = (isset($field['check']) && $field['check']) ?
                ' ' . $field['check'] : '';

            $typeDecl = $field['type']->getSqlDeclaration($field, $this);
            $columnDef = $typeDecl . $collation . $default . $notnull . $unique . $check;
        }
        return $name . ' ' . $columnDef;
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

    /** COPY FROM SQLServerPlatform **/

    private function getAlterTableAddDefaultConstraintClause($tableName, Column $column) {
        $columnDef = $column->toArray();
        $columnDef['name'] = $column->getQuotedName($this);
        return 'ADD' . $this->getDefaultConstraintDeclarationSQL($tableName, $columnDef);
    }

    private function alterColumnRequiresDropDefaultConstraint(ColumnDef $columnDiff) {
        if ( ! $columnDiff->fromColumn instanceof Column) {
            return false;
        }
        if ($columnDiff->fromColumn->getDefault() === null) {
            return false;
        }
        if ($columnDiff->hasChanged('default')) {
            return true;
        }
        if ($columnDiff->hasChanged('type') || $columnDiff->hasChanged('fixed')) {
            return true;
        }
        return false;
    }

    private function generateIdentifierName($identifier) {
        $identifier = new Identifier($identifier);
        return strtoupper(dechex(crc32($identifier->getName())));
    }

    private function generateDefaultConstraintName($table, $column) {
        return 'DF_' . $this->generateIdentifierName($table) . '_' . $this->generateIdentifierName($column);
    }

    private function getAlterTableDropDefaultConstraintClause($tableName, $columnName) {
        return 'DROP CONSTRAINT ' . $this->generateDefaultConstraintName($tableName, $columnName);
    }
}
