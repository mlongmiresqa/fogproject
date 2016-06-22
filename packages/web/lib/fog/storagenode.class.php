<?php
class StorageNode extends FOGController {
    protected $databaseTable = 'nfsGroupMembers';
    protected $databaseFields = array(
        'id' => 'ngmID',
        'name' => 'ngmMemberName',
        'description' => 'ngmMemberDescription',
        'isMaster' => 'ngmIsMasterNode',
        'storageGroupID' => 'ngmGroupID',
        'isEnabled' => 'ngmIsEnabled',
        'isGraphEnabled' => 'ngmGraphEnabled',
        'path' => 'ngmRootPath',
        'ftppath' => 'ngmFTPPath',
        'bitrate' => 'ngmMaxBitrate',
        'snapinpath' => 'ngmSnapinPath',
        'sslpath' => 'ngmSSLPath',
        'ip' => 'ngmHostname',
        'maxClients' => 'ngmMaxClients',
        'user' => 'ngmUser',
        'pass' => 'ngmPass',
        'key' => 'ngmKey',
        'interface' => 'ngmInterface',
        'bandwidth' => 'ngmBandwidthLimit',
        'webroot' => 'ngmWebroot',
    );
    protected $databaseFieldsRequired = array(
        'storageGroupID',
        'ip',
        'path',
        'ftppath',
        'user',
        'pass',
    );
    protected $additionalFields = array(
        'images',
        'snapinfiles',
        'logfiles',
    );
    public function get($key = '') {
        if (in_array($this->key($key),array('path','ftppath','snapinpath','sslpath','webroot'))) return rtrim(parent::get($key), '/');
        return parent::get($key);
    }
    public function getStorageGroup() {
        return self::getClass('StorageGroup',$this->get('storageGroupID'));
    }
    public function getNodeFailure($Host) {
        if (!$this->get('id')) return;
        $NodeFailure = array_map(function(&$Failed) {
            $CurrTime = self::nice_date();
            if ($CurrTime < self::nice_date($Failed->get('failureTime'))) return $Failed;
            unset($Failed);
        },(array)self::getClass('NodeFailureManager')->find(array('storageNodeID'=>$this->get('id'),'hostID'=>$Host)));
        $NodeFailure = array_shift($NodeFailure);
        if ($NodeFailure instanceof StorageNode && $NodeFailure->isValid()) return $NodeFailure;
    }
    public function loadLogfiles() {
        if (!$this->get('id')) return;
        $URL = array_map(function(&$path) {
            return sprintf('http://%s/fog/status/getfiles.php?path=%s',$this->get('ip'),urlencode($path));
        },array('/var/log/nginx/','/var/log/httpd/','/var/log/apache2/','/var/log/fog','/var/log/php7.0-fpm/','/var/log/php-fpm/','/var/log/php5-fpm/','/var/log/php5.6-fpm/'));
        $paths = self::$FOGURLRequests->process($URL);
        $tmppath = array();
        array_walk($paths,function(&$response,&$index) use (&$tmppath) {
            $tmppath = array_filter((array)array_merge((array)$tmppath,(array)json_decode($response,true)));
        },(array)$paths);
        $paths = array_unique((array)$tmppath);
        unset($tmppath);
        natcasesort($paths);
        $this->set('logfiles',array_values((array)$paths));
    }
    public function loadSnapinfiles() {
        if (!$this->get('id')) return;
        $URL = sprintf('http://%s/fog/status/getfiles.php?path=%s',$this->get('ip'),urlencode($this->get('snapinpath')));
        $paths = self::$FOGURLRequests->process($URL);
        $paths = array_shift($paths);
        $paths = json_decode($paths);
        $pathstring = sprintf('/%s/',trim($this->get('snapinpath'),'/'));
        if (count($paths) < 1) {
            self::$FOGFTP
                ->set('host',$this->get('ip'))
                ->set('username',$this->get('user'))
                ->set('password',$this->get('pass'));
            if (!self::$FOGFTP->connect()) return;
            $paths = self::$FOGFTP->nlist($pathstring);
            self::$FOGFTP->close();
        }
        $paths = array_values(array_unique(array_filter((array)preg_replace('#dev|postdownloadscripts|ssl#','',preg_replace("#$pathstring#",'',$paths)))));
        $this->set('snapinfiles',$paths);
    }
    public function loadImages() {
        if (!$this->get('id')) return;
        $URL = sprintf('http://%s/fog/status/getfiles.php?path=%s',$this->get('ip'),urlencode($this->get('path')));
        $paths = self::$FOGURLRequests->process($URL);
        $paths = array_shift($paths);
        $paths = json_decode($paths);
        $pathstring = sprintf('/%s/',trim($this->get('path'),'/'));
        if (count($paths) < 1) {
            self::$FOGFTP
                ->set('host',$this->get('ip'))
                ->set('username',$this->get('user'))
                ->set('password',$this->get('pass'));
            if (!self::$FOGFTP->connect()) return;
            $pathstring = sprintf('/%s/',trim($this->get('ftppath'),'/'));
            $paths = self::$FOGFTP->nlist($pathstring);
            self::$FOGFTP->close();
        }
        $paths = array_values(array_unique(array_filter((array)preg_replace('#dev|postdownloadscripts|ssl#','',preg_replace("#$pathstring#",'',$paths)))));
        $this->set('images',self::getSubObjectIDs('Image',array('path'=>$paths)));
    }
    public function getClientLoad() {
        if ($this->get('maxClients') <= 0) return (double)($this->getStorageGroup()->getUsedSlotCount() + $this->getStorageGroup()->getQueuedSlotCount()) / $this->getTotalSupportedClients();
        return (double)($this->getUsedSlotCount() + $this->getQueuedSlotCount()) / $this->get('maxClients');
    }
    public function getUsedSlotCount() {
        $UsedTasks = explode(',',self::getSetting('FOG_USED_TASKS'));
        $countTasks = 0;
        if (($index = array_search(8,$UsedTasks)) === false) return ($countTasks + self::getClass('TaskManager')->count(array('stateID'=>$this->getProgressState(),'typeID'=>$UsedTasks,'NFSMemberID'=>$this->get('id'))));
        unset($UsedTasks[$index]);
        $UsedTasks = array_values(array_filter((array)$UsedTasks));
        $countTasks = count(array_unique(self::getSubObjectIDs('MulticastSessionsAssociation',array('taskID'=>self::getSubObjectIDs('Task',array('stateID'=>$this->getProgressState(),'typeID'=>8))),'msID')));
        return ($countTasks + self::getClass('TaskManager')->count(array('stateID'=>$this->getProgressState(),'typeID'=>$UsedTasks,'NFSMemberID'=>$this->get('id'))));
    }
    public function getQueuedSlotCount() {
        $UsedTasks = explode(',',self::getSetting('FOG_USED_TASKS'));
        $countTasks = 0;
        if (($index = array_search(8,$UsedTasks)) === false) return ($countTasks + self::getClass('TaskManager')->count(array('stateID'=>$this->getQueuedStates(),'typeID'=>$UsedTasks,'NFSMemberID'=>$this->get('id'))));
        unset($UsedTasks[$index]);
        $UsedTasks = array_values(array_filter((array)$UsedTasks));
        $countTasks = count(array_unique(self::getSubObjectIDs('MulticastSessionsAssociation',array('taskID'=>self::getSubObjectIDs('Task',array('stateID'=>$this->getQueuedStates(),'typeID'=>8))),'msID')));
        return ($countTasks + self::getClass('TaskManager')->count(array('stateID'=>$this->getQueuedStates(),'typeID'=>$UsedTasks,'NFSMemberID'=>$this->get('id'))));
    }
}
