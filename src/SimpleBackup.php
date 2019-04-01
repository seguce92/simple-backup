<?php

namespace Coderatio\SimpleBackup;

use Coderatio\SimpleBackup\Foundation\Configurator;
use Coderatio\SimpleBackup\Foundation\Database;
use RuntimeException;
use Coderatio\SimpleBackup\Foundation\Provider;

/***************************************************
 * @Author: Coderatio
 * @Developer: Josiah Ovye Yahaya
 * @Licence: MIT
 * @Type: Library
 * @Title: Simple database backup
 * @Desc: A clean and simple mysql database backup library
 * @DBSupport: MySQL
 **************************************************/
class SimpleBackup
{
    /** @var array $config */
    protected $config = [];

    /** @var mixed $connection */
    protected $connection;

    /** @var string $contents */
    protected $contents = '';

    /** @var string $export_name */
    protected $export_name = '';

    /** @var string $response */
    protected $response = '';

    /** @var bool $to_download */
    protected $to_download = false;

    /**
     * Get the instance of the class
     *
     * @return SimpleBackup
     */
    public static function instance()
    {
        return new self();
    }


    /**
     * Set up mysql database connection details
     * @param array $config
     * @return $this
     */
    public static function setDatabase($config = [])
    {
        $self = new self();

        $self->parseConfig($config);

        return $self;
    }

    /**
     * Parse the config as associative or non-associative array
     * @param array $config
     * @return $this
     */
    protected function parseConfig($config = [])
    {
        $this->config = Configurator::parse($config);

        return $this;
    }

    /**
     * Get the database tables
     * @return array
     */
    protected function getTargetTables()
    {
        $mysqli = Database::prepare($this->config);

        $this->connection = $mysqli;

        $mysqli->select_db($this->config['db_name']);
        $mysqli->query("SET NAMES 'utf8'");

        $target_tables = [];

        $query_tables = $mysqli->query('SHOW TABLES');

        while ($row = $query_tables->fetch_row()) {
            $target_tables[] = $row[0];
        }

        $this->config['tables'] = false;

        if ($this->config['tables'] !== false) {
            $target_tables = array_intersect($target_tables, $this->config['tables']);
        }

        $this->contents = Configurator::insertDumpHeader(
            $this->connection,
            $this->config
        );

        return $target_tables;
    }

    /**
     * Build the sql pre_insert_statements to export
     * @return $this
     */
    protected function prepareExportContentsFrom($file_path)
    {
        Provider::init($this->config)->start($file_path);

        $header = Configurator::insertDumpHeader(
            $this->connection,
            $this->config
        );

        $this->contents = file_get_contents($file_path);

        $this->contents = str_replace('-- mysqldump-php https://github.com/ifsnop/mysqldump-php', $header, $this->contents);

        $this->contents = str_replace('-- Server version 	5.7.24', '', $this->contents);

        return $this;
    }

    /**
     * This function takes care of the importing.
     * You should provide an sql file or contents.
     *
     * @param string $sql_file_OR_content
     * @param array $config
     * @return $this
     */
    public function importFrom($sql_file_OR_content, $config = [])
    {
        if (!empty($config)) {
            $this->parseConfig($config);
        }

        //exit(var_dump(Provider::init($this->config)->start('backups/dump.sql')));

        // Increase script loading time
        set_time_limit(3000);

        $sql_contents = (strlen($sql_file_OR_content) > 300
            ? $sql_file_OR_content
            : file_get_contents($sql_file_OR_content));

        $allLines = explode("\n", $sql_contents);

        $target_tables = $this->getTargetTables();

        $mysqli = $this->connection;

        $mysqli->query('SET foreign_key_checks = 0');
        preg_match_all("/\nCREATE TABLE(.*?)\`(.*?)\`/si", "\n" . $sql_contents, $target_tables);

        // Let's drop all tables on the database first.
        foreach ($target_tables[2] as $table) {
            //$this->connection->query("TRUNCATE TABLE {$table}");
            $mysqli->query('DROP TABLE IF EXISTS ' . $table);
        }

        $mysqli->query('SET foreign_key_checks = 0');
        $mysqli->query("SET NAMES 'utf8'");

        $templine = '';    // Temporary variable, used to store current query

        // Loop through each line
        foreach ($allLines as $line) {

            // (if it is not a comment..) Add this line to the current segment
            if ($line != '' && strpos($line, '--') !== 0) {
                $templine .= $line;

                // If it has a semicolon at the end, it's the end of the query
                if (substr(trim($line), -1, 1) == ';') {
                    if (!$mysqli->query($templine)) {
                        print("
                            <strong>Error performing query</strong>: {$templine} {$mysqli->error} <br/><br/>
                        ");
                    }

                    // set variable to empty, to start picking up the lines after ";"
                    $templine = '';
                }
            }
        }

        $this->response = 'Importing finished successfully.';

        return $this;
    }

    /**
     * This function allows you download the export.
     *
     * @param string $export_name
     */
    public function downloadAfterExport($export_name = '')
    {
        $this->abortIfEmptyTables();

        $this->to_download = true;

        if (!empty($export_name)) {
            $this->export_name = $export_name;
        }

        $export_name = !empty($this->export_name)
            ? "{$this->export_name}.sql"
            : $this->config['db_name'] . '_db_backup_(' . date('H-i-s') . '_' . date('d-m-Y') . ').sql';

        $this->export_name = $export_name;

        $file_path = "backups/{$export_name}";

        $this->prepareExportContentsFrom($file_path);

        ob_get_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Transfer-Encoding: Binary');
        header('Content-Length: ' . (function_exists('mb_strlen') ? mb_strlen($this->contents, '8bit') : strlen($this->contents)));

        header('Content-disposition: attachment; filename="' . $export_name . '"');

        echo $this->contents;

        $this->response = 'Export completed successfully';

        @unlink($file_path);

        exit;
    }

    /**
     * This method allows you store the exported db to a directory
     *
     * @param $path_to_store
     * @param null $name
     * @return $this
     */
    public function storeAfterExportTo($path_to_store, $name = null)
    {
        $this->abortIfEmptyTables();


        $export_name = $this->config['db_name'] . '_db_backup_(' . date('H-i-s') . '_' . date('d-m-Y') . ').sql';

        if ($name !== null) {
            $export_name = str_replace('.sql', '', $name) . '.sql';
        }

        $this->export_name = $export_name;

        if (!file_exists($path_to_store) && !mkdir($path_to_store)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $path_to_store));
        }

        $file_path = $path_to_store . '/' . $export_name;

        $this->prepareExportContentsFrom($file_path);


        $file = fopen($file_path, 'wb') or die('Unable to open file!');
        fwrite($file, $this->contents);
        fclose($file);

        return $this;
    }

    /**
     * Get the just exported file name
     *
     * @return string
     */
    public function getExportedName()
    {
        return $this->export_name;
    }

    /**
     * This is used to chain more methods.
     * You can pass in a function to modify any other thing.
     *
     * @param null $callback
     * @return $this
     */
    public function then($callback = null)
    {
        if ($callback !== null && is_callable($callback)) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Get the response for each action.
     *
     * @return string
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Check if database has atleast one table.
     *
     * @return $this
     */
    protected function abortIfEmptyTables()
    {
        if (empty($this->getTargetTables())) {
            die('No tables found on ' . $this->config['db_name']);
        }

        return $this;
    }
}