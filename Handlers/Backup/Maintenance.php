<?php
/**
 * Copyright Sangoma Technologies, Inc 2017
 */
namespace FreePBX\modules\Backup\Handlers\Backup;

use Carbon\Carbon;
use FreePBX\modules\Backup\Handlers as Handler;

class Maintenance extends \FreePBX\modules\Backup\Handlers\CommonBase {
	private $dryrun = false;
	private $backupInfo;
	private $remoteStorage;
	private $name;
	private $spooldir;
	private $serverName;
	private $localPath;

	public function __construct($freepbx, $id, $transactionId, $pid) {
		parent::__construct($freepbx, $transactionId, $pid);
		$this->id = $id;
		$this->backupInfo = $this->freepbx->Backup->getBackup($this->id);
		$this->remoteStorage = $this->freepbx->Backup->getStorageById($this->id);
		$this->name = str_replace(' ', '_', $this->backupInfo['backup_name']);
		$this->spooldir = $this->freepbx->Config->get("ASTSPOOLDIR");
		$this->serverName = str_replace(' ', '_',$this->freepbx->Config->get('FREEPBX_SYSTEM_IDENT'));
		$this->localPath = sprintf('%s/backup/%s',$this->spooldir,$this->name);
	}

	public function setDryRun($mode){
		$this->dryrun = $mode;
	}

	public function processLocal(){
		$files = new \GlobIterator($this->localPath.'/*.tar.gz*');
		$maintfiles = [];
		foreach ($files as $file) {
			$parsed = $this->parseFile($file->getBasename());
			if($parsed === false){
				continue;
			}
			$backupDate = Carbon::createFromTimestamp($parsed['timestamp'], 'UTC');
			if(isset($this->backupInfo['maintage']) && $this->backupInfo['maintage'] > 1){
				if($backupDate->diffInDays() > $this->backupInfo['maintage']){
					$this->log(sprintf("Removing %s/%s",$file->getPath(),$file->getBasename().'.tar.gz'),'DEBUG');
					if($this->dryrun){
						continue;
					}
					$this->fs->remove($file->getPath().'/'.$file->getBasename());
					continue;
				}
			}
			if($this->dryrun){
				$this->log("\t".sprintf("Adding %s/%s to maintfiles with a key of %s",$file->getPath(),$file->getBasename(),$parsed['timestamp']),'DEBUG');
			}
			$maintfiles[$parsed['timestamp']] = $file->getPath().'/'.$file->getBasename();
		}
		asort($maintfiles,SORT_NUMERIC);
		if(isset($this->backupInfo['maintruns']) && $this->backupInfo['maintruns'] > 1){
			$remove = array_slice($maintfiles,$this->backupInfo['maintruns'],null,true);
			foreach ($remove as $key => $value) {
				$this->log(sprintf("Removing %s",$value),'DEBUG');
				if($this->dryrun){
					continue;
				}
				$this->fs->remove($value);
			}
		}
	}

	public function processRemote(){
		foreach ($this->remoteStorage as $location) {
			$maintfiles = [];
			$id = explode('_', $location)[1];
			try {
				$info = $this->freepbx->Filestore->getItemById($id);
				if(empty($info)) {
					$this->log(_('Invalid filestore location'),'ERROR');
					continue;
				}
				$files = $this->freepbx->Filestore->ls($id);
			} catch (\Exception $e) {
				$this->log($e->getMessage(),'ERROR');
				$files = [];
			}

			foreach ($files as $file) {
				if(!isset($file['path'])){
					continue;
				}
				$parsed = $this->parseFile($file['basename']);
				if($parsed === false){
					continue;
				}
				$backupDate = Carbon::createFromTimestamp($parsed['timestamp'], 'UTC');
				if(isset($this->backupInfo['maintage']) && $this->backupInfo['maintage'] > 1){
					if($backupDate->diffInDays() > $this->backupInfo['maintage']){
						try {
							$this->log("\t".sprintf(_("Removing %s"),$file['path']),'DEBUG');
							if($this->dryrun){
								continue;
							}
							$this->freepbx->Filestore->delete($id,$file['path']);
						} catch (\Exception $e) {
							$this->log($e->getMessage(),'ERROR');
							continue;
						}
						continue;
					}
				}
				$maintfiles[$parsed['timestamp']] = $file['path'];
			}
			if(isset($this->backupInfo['maintruns']) && $this->backupInfo['maintruns'] > 0){
				$remove = array_slice($maintfiles,$this->backupInfo['maintruns'],null,true);
				foreach ($remove as $key => $value) {
					try {
						$this->log("\t".sprintf(_("Removing %s"),$value),'DEBUG');
						if($this->dryrun){
							continue;
						}
						$this->freepbx->Filestore->delete($id,$value);
					} catch (\Exception $e) {
						$this->log($e->getMessage(),'ERROR');
						continue;
					}
				}
			}
		}
	}

	private function parseFile($filename){
		//20171012-130011-1507838411-15.0.1alpha1-42886857.tar.gz
		preg_match("/(\d{7})-(\d{6})-(\d{10,11})-(.*)-\d*\.tar\.gz/", $filename, $output_array);
		$valid = false;
		$arraySize = sizeof($output_array);
		if($arraySize == 5){
			$valid = true;
		}
		if($arraySize == 6){
			$valid = true;
		}
		if(!$valid){
			return false;
		}
		return [
			'filename' => $output_array[0],
			'datestring' => $output_array[1],
			'timestring' => $output_array[2],
			'timestamp' => $output_array[3],
			'framework' => $output_array[4]
		];
	}
}
