<?php

use RandomizedTests\Tooling\ApacheConfigGenerator;
use RandomizedTests\Tooling\DockerComposeFileGenerator;
use RandomizedTests\Tooling\MakefileGenerator;
use RandomizedTests\Tooling\PhpFpmConfigGenerator;
use RandomizedTests\Tooling\RequestTargetsGenerator;

include __DIR__ . '/config/envs.php';
include __DIR__ . '/config/inis.php';
include __DIR__ . '/config/platforms.php';
include __DIR__ . '/lib/ApacheConfigGenerator.php';
include __DIR__ . '/lib/DockerComposeFileGenerator.php';
include __DIR__ . '/lib/MakefileGenerator.php';
include __DIR__ . '/lib/PhpFpmConfigGenerator.php';
include __DIR__ . '/lib/RequestTargetsGenerator.php';

const TMP_SCENARIOS_FOLDER = __DIR__ . '/.tmp.scenarios';
const MAX_ENV_MODIFICATIONS = 5;
const MAX_INI_MODIFICATIONS = 5;

function generate()
{
    $scenariosFolder = TMP_SCENARIOS_FOLDER;

    $options = getopt('', ['scenario:']);

    $testDataByIdentifier = [];
    if (isset($options['scenario'])) {
        // Generate only one scenario
        $seed = intval($options['scenario']);
        $testDataByIdentifier = array_merge($testDataByIdentifier, generateOne($seed));
    } else {
        // If a scenario number has not been provided, we generate a number of different scenarios based on based
        // configuration
        $options = getopt('', ['seed:', 'number:']);
        $seed = isset($options['seed']) ? intval($options['seed']) : rand();
        srand($seed);
        echo "Using seed: $seed\n";

        if (empty($options['number'])) {
            echo "Error: --number option is required to set the number of scenarios to create.\n";
            exit(1);
        }
        $numberOfScenarios = intval($options['number']);

        for ($iteration = 0; $iteration < $numberOfScenarios; $iteration++) {
            $scenarioSeed = rand();
            $testDataByIdentifier = array_merge($testDataByIdentifier, generateOne($scenarioSeed));
        }
    }

    (new MakefileGenerator())->generate("$scenariosFolder/Makefile", array_keys($testDataByIdentifier));
    (new DockerComposeFileGenerator())->generate("$scenariosFolder/docker-compose.yml", $testDataByIdentifier);
}

function generateOne($scenarioSeed)
{
    srand($scenarioSeed);
    $selectedOs = array_rand(OS);
    $availablePHPVersions = OS[$selectedOs]['php'];
    $selectedPhpVersion = $availablePHPVersions[array_rand($availablePHPVersions)];
    $selectedInstallationMethod = INSTALLATION[array_rand(INSTALLATION)];

    // Environment variables modifications
    $numberOfEnvModifications = rand(0, MAX_ENV_MODIFICATIONS);
    $envModifications = array_merge([], DEFAULT_ENVS);
    for ($envModification = 0; $envModification < $numberOfEnvModifications; $envModification++) {
        $currentEnv = array_rand(ENVS);
        $availableValues = ENVS[$currentEnv];
        $selectedEnvValue = $availableValues[array_rand($availableValues)];
        if (null === $selectedEnvValue) {
            unset($envModifications[$currentEnv]);
        } else {
            $envModifications[$currentEnv] = $selectedEnvValue;
        }
    }

    // INI settings modification
    $numberOfIniModifications = rand(0, min(MAX_INI_MODIFICATIONS, count(INIS)));
    $iniModifications = [];
    for ($iniModification = 0; $iniModification < $numberOfIniModifications; $iniModification++) {
        $currentIni = array_rand(INIS);
        $availableValues = INIS[$currentIni];
        $iniModifications[$currentIni] = $availableValues[array_rand($availableValues)];
    }
    $identifier = "randomized-$scenarioSeed-$selectedOs-$selectedPhpVersion";
    $scenarioFolder = TMP_SCENARIOS_FOLDER . "/$identifier";

    // Preparing folder
    exec("mkdir -p $scenarioFolder/app");
    exec("cp -r ./app $scenarioFolder/");
    exec("cp $scenarioFolder/app/composer-$selectedPhpVersion.json $scenarioFolder/app/composer.json");

    // Writing scenario specific files
    (new ApacheConfigGenerator())->generate("$scenarioFolder/www.apache.conf", $envModifications, $iniModifications);
    (new PhpFpmConfigGenerator())->generate("$scenarioFolder/www.php-fpm.conf", $envModifications, $iniModifications);
    (new RequestTargetsGenerator())->generate("$scenarioFolder/vegeta-request-targets.txt", 2000);

    return [
        $identifier => [
            'identifier' => $identifier,
            'scenario_folder' => $scenarioFolder,
            'image' => "datadog/dd-trace-ci:php-randomizedtests-$selectedOs-$selectedPhpVersion",
            'installation_method' => $selectedInstallationMethod,
        ],
    ];
}

generate();
