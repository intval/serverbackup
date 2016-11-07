<?php
use Symfony\Component\Console\Output\OutputInterface;
use Sabre\DAV\Client;
use League\Flysystem\Filesystem;
use League\Flysystem\WebDAV\WebDAVAdapter;

ini_set('memory_limit', '640M');
set_time_limit(0);




$time = time();
$backupDir = "/tmp/backup/$time";
$backupFile = "/tmp/backup/$time.tar.bz2";

$cmd =
[
    "dir" => "mkdir $backupDir",
    "db" => "mysqldump -u {dbuser} {dbpass} --all-databases -r $backupDir/database.sql",
    "folders" => [
        "/var/www",
        "/var/replserver/current/",
        "/var/mysqlcourse/current/",
        "/var/esdata",
        "/etc/nginx"
    ],
    "archive" => "tar -cvjSf $backupFile $backupDir",
    "uploadwebdav" => [ "baseUri" => "https://webdav.yandex.com"],
    "clean" => "rm -rf $backupDir",
    "cleanfile" => "rm -rf $backupFile"
];


if(!is_dir("/tmp/backup"))
    mkdir("/tmp/backup") || die("mkdir /tmp/backup");


require_once __DIR__.'/vendor/autoload.php';


class MyApp extends Silly\Application {

    public function execCommand($key, $command, $output){

        $output->writeln("<info>Step: $key</info>");
        $command .= "  2>&1 ";
        $out = []; $retCode = 0;
        $outStr = exec($command, $out, $retCode);

        if($retCode != 0) {
            $output->writeln($command);
            $output->writeln($retCode . " " . $outStr . join(" ", $out));
            return;
        }
    }
}

$app = new MyApp();

$app->command('backup webdavUname webdavPass dbuname [dbpass]',
    function ($webdavUname, $webdavPass, $dbuname, $dbpass, OutputInterface $output)
    use ($cmd, $backupFile, $backupDir, $app) {

    $output->writeln("<info>Starting backup</info>");
    $cmd["db"] = str_replace("{dbuser}", $dbuname, $cmd["db"]);

    if(!empty(trim($dbpass))) $dbpass = "-p".$dbpass;
    $cmd["db"] = str_replace("{dbpass}", $dbpass, $cmd["db"]);

    foreach($cmd as $key => $command) {

        switch($key)
        {
            case "folders":
                foreach($command as $folder)
                {
                    if(!is_dir($folder))
                    {
                        $output->writeln("<comment>Folder $folder wasn't found. Skipping</comment>");
                        continue;
                    }

                    $target = str_replace("/", "_", $folder);
                    $c = "cp -R $folder $backupDir/$target/ 2>&1";
                    $app->execCommand("copy folder $folder", $c, $output);
                }
                break;
            
            case "uploadwebdav":
                if(!file_exists($backupFile) || !is_readable($backupFile))
                {
                    $output->writeln("<error>File $backupFile wasn't found</error>");
                    return;
                }

                $cmd["uploadwebdav"]["userName"] = $webdavUname;
                $cmd["uploadwebdav"]["password"] = $webdavPass;

                $client = new Client($cmd["uploadwebdav"]);
                $adapter = new WebDAVAdapter($client);
                $flysystem = new Filesystem($adapter);

                $fp = fopen($backupFile, 'r');
                $dest = 'backup/'.strftime('%G-%m-%d %H:%M').'.tar.bz2';
                $flysystem->writeStream($dest, $fp);
                $flysystem->has($dest);
                $output->writeln("<info>Step upload to $dest</info>");
                break;
            
            default:
                $app->execCommand($key, $command, $output);
        }

            

    }
});

$app->run();

