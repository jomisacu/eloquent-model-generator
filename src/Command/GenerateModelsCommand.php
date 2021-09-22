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

        $tables = $this->getTables(array_key_exists('only-with-id', $configBase));
        $tablesToSkip = $this->getTablesToSkip();
        $tablesToSkip = array_flip($tablesToSkip);

        foreach ($tables as $table) {
            if (isset($tablesToSkip[$table['name']])) continue;

            $modelConfig = $configBase;

            $modelConfig['table-name'] = $table['name'];
            $modelConfig['class-name'] = $this->getClassNameFromTableName($table['name']);
            $modelConfig['no-timestamps'] = $table['no-timestamps'] ? 1 : null;

            $configObject = $this->createConfig($modelConfig);

            if (!$this->modelExists($configObject)) {
                $model = $this->generator->generateModel($configObject);

                $this->output->writeln(sprintf('Model %s generated', $model->getName()->getName()));

                if ($configObject->has('as_abstract')) {
                    $this->makeAbstract($configObject);
                }
            } else {
                $this->output->writeln(sprintf('Model %s already exists, skipping it.', $configObject->get('class_name')));
            }
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
            ['only-with-id', 'wi', InputOption::VALUE_NONE, 'Table must have id column', null],
            ['as-abstract', 'aa', InputOption::VALUE_NONE, 'Class will be generated as abstract', null],
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

    private function getTables($onlyWithId = false)
    {
        $rows = DB::select("
            SELECT * 
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME IN (SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE')
        ");
        $tables = [];

        foreach (collect($rows)->groupBy('TABLE_NAME') as $tableName => $columns) {
            $columns = collect($columns)->keyBy('COLUMN_NAME')->all();
            $columnNames = array_map('strtolower', array_keys($columns));

            if ($onlyWithId && !in_array('id', $columnNames)) continue;

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

    private function modelExists(Config $configObject)
    {
        return file_exists($this->getFilepath($configObject));
    }

    private function makeAbstract(Config $configObject)
    {
        $filepath = $this->getFilepath($configObject);

        $fileContents = file_get_contents($filepath);

        $className = $configObject->get('class_name');

        $fileContents = str_replace('class '.$className, 'abstract class '.$className, $fileContents);

        file_put_contents($filepath, $fileContents);
    }

    private function getFilepath(Config $configObject): string
    {
        $path = $configObject->get('output_path');
        if ($path === null || stripos($path, '/') !== 0) {
            if (function_exists('app_path')) {
                $path = app_path($path);
            } else {
                $path = app('path').($path ? DIRECTORY_SEPARATOR.$path : $path);
            }
        }

        return $path.'/'.$configObject->get('class_name').'.php';
    }
}
