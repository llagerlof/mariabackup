<?php
// Validate if any option was provided
if (count($argv) == 1) {
    echo("> Usage:\n");
    echo("  php mariabackup.php --databases=*\n");
    die("  php mariabackup.php --databases=db1,db2\n");
}

// User selected databases
$databases_selected = pvalues('--databases');

// Connect to MariaDB database using PDO
try {
    $db = new PDO('mysql:host=localhost', 'root', '');
} catch (Exception $e) {
    die("> Error connecting to database server.  (exception message: " . $e->getMessage() . ")\n");
}

// Get the @@GLOBAL.basedir to use on directory name
$basedir = statement('select @@GLOBAL.basedir as basedir', 'Error retrieving @@GLOBAL.basedir.')->fetchColumn();

// Get the @@GLOBAL.datadir to use on directory name
$datadir = statement('select @@GLOBAL.datadir as datadir', 'Error retrieving @@GLOBAL.datadir.')->fetchColumn();

// Get all system variables. Will be included in SYSTEM_VARIABLES.txt
$system_variables = statement('show variables','Error retrieving system variables.')->fetchAll(PDO::FETCH_ASSOC);
$csv_system_variables = array2csv($system_variables);

// Get all users and hosts. Will be included in USER_HOSTS.txt
$user_hosts = statement('select distinct u.user as user, u.host as host from mysql.user u', 'Error retrieving users and hosts.')->fetchAll(PDO::FETCH_ASSOC);
$csv_user_hosts = array2csv($user_hosts);

// Get all grants for $user_hosts. Will be included in PERMISSIONS.txt
$grants_commands = '';
foreach ($user_hosts as $user_host) {
    $grants_commands .= $user_host['user'] . '@' . $user_host['host'] . "\n\n";

    $grants = statement("show grants for '" . $user_host['user'] . "'@'" . $user_host['host']."'", 'Error retrieving grants.')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($grants as $grant) {
        $grants_commands .= $grant . "\n";
    }
    $grants_commands .= "\n\n";
}

// Get all databases names
$databases = statement("show databases", "Error retrieving databases list.")->fetchAll(PDO::FETCH_COLUMN);

// Check if at least one database was returned
if (count($databases) === 0) {
    die("> No databases found.\n");
}

// Make sure one or more selected databases exists
if (in_array('*', $databases_selected)) {
    $databases_selected = $databases;
} else {
    $databases_selected = array_intersect($databases, $databases_selected);
}

// Check if at least one database was selected
if (empty($databases_selected)) {
    die("> No database(s) selected.\n");
}

// Print selected databases
echo("> Selected databases:\n");
foreach ($databases_selected as $database) {
    echo("  - " . $database . "\n");
}

echo "\n";

// Create the backup directory
$backup_dir = getcwd() . '/backup-db_' . date('Y-m-d_H-i-s') . '_basedir[' . str2filename($basedir) . ']_datadir[' . str2filename($datadir) . ']';

if (file_exists($backup_dir) && !is_dir($backup_dir)) {
    die("> Error: Directory $backup_dir could not be created because a file with the same name already exists.\n");
}

if (!is_dir($backup_dir)) {
    if (!is_writable(dirname(__FILE__))) {
        die("> Error: Directory $backup_dir could not be created. Permission denied.\n");
    } else {
        echo "> Creating directory $backup_dir...\n";
        mkdir($backup_dir, 0776);
    }
}

// Check if backup directory already have backup files (.sql)
if (!empty(glob("$backup_dir/*.sql"))) {
    echo "> Error: Directory $backup_dir already contains backup files.\n\n";
    $a = readline("Overwrite existing files? (y/n) [default n]: ");
    if (trim(strtolower($a)) != 'y') {
        die("\n> Backup cancelled.\n");
    }
}

// Backup system variables
echo "\n> Backuping system variables to SYSTEM_VARIABLES.txt... ";
file_put_contents("$backup_dir/SYSTEM_VARIABLES.txt", $csv_system_variables);
echo "done.\n";

// Backup users ans hosts
echo "\n> Backuping users and hosts to USER_HOSTS.txt... ";
file_put_contents("$backup_dir/USERS_HOSTS.txt", $csv_user_hosts);
echo "done.\n";

// Backup grants
echo "\n> Backuping grants to PERMISSIONS.txt... ";
file_put_contents("$backup_dir/PERMISSIONS.txt", $grants_commands);
echo "done.\n";

// Make a backup of each database in the list to a separate file using mysqldump
foreach ($databases_selected as $database) {
    echo "\n> Backuping database {$database} to $backup_dir... ";
    $cmd = "mysqldump --routines --triggers --single-transaction -u root $database > $backup_dir/$database.sql";
    exec($cmd);
    echo "done.\n";
}

die("\n> Backup finished.\n");


/* Utility functions */

/**
 * Convert string to a valid filename
 *
 * @param string $str Any string
 *
 * @return string Valid and safe filename
 */
function str2filename(string $str): string
{
    // Replace ":\" with "."
    $str = preg_replace('/:\\\/', '.', $str);
    // Replace "\" with "."
    $str = preg_replace('/\\\/', '.', $str);
    // Replace "/" with "."
    $str = preg_replace('/\//', '.', $str);
    // Replace all spaces with underscores
    $str = str_replace(' ', '_', $str);
    // Replace all non alphanumeric characters, not including "-", "_" and ".", with dashes
    $str = preg_replace('/[^A-Za-z0-9\-_\.]/', '-', $str);
    // Replace a sequence of "." with one "."
    $str = preg_replace('/\.+/', '.', $str);
    // Replace a sequence of blank characters with one space
    $str = preg_replace('/\s+/', ' ', $str);
    // Replace a sequence of underscores with one underscore
    $str = preg_replace('/_+/', '_', $str);
    // Replace a sequence of hiphens with one hiphen
    $str = preg_replace('/-+/', '-', $str);
    // Remove all "-", "_" and "." from the end of the string
    $str = preg_replace('/[\-_\.]+$/', '', $str);
    // Remove all "-", "_" and "." from the start of the string
    $str = preg_replace('/^[\-_\.]+/', '', $str);

    return trim($str);
}

/**
 * Convert a 2d array, table-like, to CSV format
 *
 * @param array $array_2d
 *
 * @return string CSV
 */
function array2csv(array $array_2d): string
{
    if (!is_array($array_2d)) {
        return '';
    }
    if (empty($array_2d)) {
        return '';
    }

    $csv = '';
    $column_position = 1;
    $column_count = count($array_2d[0]);
    foreach ($array_2d[0] as $column => $value) {
        $csv .= $column . ($column_position != $column_count ? ',' : "\n");
        $column_position++;
    }
    foreach ($array_2d as $row) {
        $csv .= implode(',', $row) . "\n";
    }

    return $csv;
}

/**
 * Parameter value. Return the parameter value passed to the script, or false if not found in the command line arguments.
 *
 * @param string $param
 *
 * @return string|bool
 */
function pvalue(string $param)
{
    global $argv;

    $param_value = false;
    foreach($argv as $arg) {
        if (strpos($arg, $param . '=') !== false) {
            $param_pieces = explode("=", $arg);
            $param_value = trim($param_pieces[1] ?? '');
        }
    }

    return $param_value;
}

/**
 * Parameter values. Return an array with all the parameter comma-separated values.
 *
 * @param string $param
 *
 * @return array
 */
function pvalues(string $param): array
{
    global $argv;

    $param_value = pvalue($param);

    return array_filter(explode(',', $param_value));
}

/**
 * Execute a query and return a PDOStatement object.
 *
 * @param string $query
 * @param string $error_message
 *
 * @return PDOStatement
 */
function statement(string $query, string $error_message): PDOStatement
{
    global $db;

    try {
        $stmt = $db->query($query);
    } catch (Exception $e) {
        die("> " . $error_message . "  (exception message: " . $e->getMessage() . ")\n");
    }

    return $stmt;
}
