<?php

namespace Krlove\EloquentModelGenerator\Command;

use Illuminate\Config\Repository as AppConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Krlove\EloquentModelGenerator\Config;
use Krlove\EloquentModelGenerator\Generator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class GenerateModelCommand
 * @package Krlove\EloquentModelGenerator\Command
 */
class GenerateModelsCommand extends Command
{
    /**
     * @var string
     */
    protected $name = 'krlove:generate:models';

    /**
     * @var Generator
     */
    protected $generator;

    /**
     * @var AppConfig
     */
    protected $appConfig;

    /**
     * GenerateModelCommand constructor.
     * @param Generator $generator
     * @param AppConfig $appConfig
     */
    public function __construct(Generator $generator, AppConfig $appConfig)
    {
        parent::__construct();

        $this->generator = $generator;
        $this->appConfig = $appConfig;
    }

    /**
     * Executes the command
     */
    public function fire()
    {
        $configBase = $this->createConfigArray();

        $tables = $this->getTables();
        $tablesToSkip = $this->getTablesToSkip();
        $tablesToSkip = array_flip($tablesToSkip);

        foreach ($tables as $table) {
            if (isset($tablesToSkip[$table['name']])) continue;

            $config = $configBase;

            $config['table-name'] = $table['name'];
            $config['class-name'] = $this->getClassNameFromTableName($table['name']);
            $config['no-timestamps'] = $table['no-timestamps'] ? 1 : null;

            $model = $this->generator->generateModel($this->createConfig($config));

            $this->output->writeln(sprintf('Model %s generated', $model->getName()->getName()));
        }

    }

    /**
     * Add support for Laravel 5.5
     */
    public function handle()
    {
        $this->fire();
    }

    /**
     * @return array
     */
    protected function createConfigArray()
    {
        $config = [];

        foreach ($this->getArguments() as $argument) {
            $config[$argument[0]] = $this->argument($argument[0]);
        }
        foreach ($this->getOptions() as $option) {
            $value = $this->option($option[0]);
            if ($option[2] == InputOption::VALUE_NONE && $value === false) {
                $value = null;
            }
            $config[$option[0]] = $value;
        }

        $config['db_types'] = $this->appConfig->get('eloquent_model_generator.db_types');

        return $config;
    }

    /**
     * @return Config
     */
    protected function createConfig($config)
    {
        return new Config($config, $this->appConfig->get('eloquent_model_generator.model_defaults'));
    }

    /**
     * @return array
     */
    protected function getArguments()
    {
        return [
        ];
    }

    /**
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['as-abstract', 'aa', InputOption::VALUE_OPTIONAL, 'Class will be generated as abstract', null],
            ['output-path', 'op', InputOption::VALUE_OPTIONAL, 'Directory to store generated model', null],
            ['namespace', 'ns', InputOption::VALUE_OPTIONAL, 'Namespace of the model', null],
            ['base-class-name', 'bc', InputOption::VALUE_OPTIONAL, 'Model parent class', null],
            ['no-timestamps', 'ts', InputOption::VALUE_NONE, 'Set timestamps property to false', null],
            ['date-format', 'df', InputOption::VALUE_OPTIONAL, 'dateFormat property', null],
            ['connection', 'cn', InputOption::VALUE_OPTIONAL, 'Connection property', null],
            ['backup', 'b', InputOption::VALUE_NONE, 'Backup existing models', null]
        ];
    }

    private function getClassNameFromTableName($table)
    {
        return Str::ucfirst(Str::camel($table));
    }

    private function getTables()
    {
        $rows = DB::select("
            SELECT * 
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME IN (SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE')
        ");
        $tables = [];

        foreach (collect($rows)->groupBy('TABLE_NAME') as $tableName => $columns) {
            $columns = collect($columns)->keyBy('COLUMN_NAME')->all();
            $noTimestamps = !isset($columns['created_at']) || !isset($columns['updated_at']);
            $table = [
                'name' => $tableName,
                'no-timestamps' => $noTimestamps
            ];
            $tables[$tableName] = $table;
        }

        return $tables;
    }

    private function getTablesToSkip()
    {
        $tables = $this->appConfig->get('eloquent_model_generator.model_defaults.tables_to_skip') ?: [];

        return array_map('strtolower', $tables);
    }
}
