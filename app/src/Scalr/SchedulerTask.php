<?php

use Scalr\LoggerTrait;

class Scalr_SchedulerTask extends Scalr_Model
{
    use LoggerTrait;

    protected $dbTableName = 'scheduler';
    protected $dbPrimaryKey = 'id';
    protected $dbMessageKeyNotFound = 'Scheduler task #%s not found in database';

    const SCRIPT_EXEC = 'script_exec';
    const TERMINATE_FARM = 'terminate_farm';
    const LAUNCH_FARM = 'launch_farm';

    const STATUS_ACTIVE = "Active";
    const STATUS_SUSPENDED = "Suspended";
    const STATUS_FINISHED = "Finished";

    const TARGET_FARM = 'farm';
    const TARGET_ROLE = 'role';
    const TARGET_INSTANCE = 'instance';

    protected $dbPropertyMap = array(
        'id'                    => 'id',
        'name'                  => 'name',
        'type'                  => 'type',
        'comments'              => 'comments',
        'target_id'             => array('property' => 'targetId'),
        'target_server_index'   => array('property' => 'targetServerIndex'),
        'target_type'           => array('property' => 'targetType'),
        'script_id'             => array('property' => 'scriptId'),
        'start_time'            => array('property' => 'startTime'),
        'end_time'              => array('property' => 'endTime'),
        'last_start_time'       => array('property' => 'lastStartTime'),
        'restart_every'         => array('property' => 'restartEvery'),
        'config'                => array('property' => 'config', 'type' => 'serialize'),
        'order_index'           => array('property' => 'orderIndex'),
        'timezone'              => 'timezone',
        'status'                => 'status',
        'account_id'            => array('property' => 'accountId'),
        'env_id'                => array('property' => 'envId')
    );

    public
        $id,
        $name,
        $type,
        $comments,
        $targetId,
        $targetServerIndex,
        $targetType,
        $scriptId,
        $startTime,
        $endTime,
        $lastStartTime,
        $restartEvery,
        $config,
        $orderIndex,
        $timezone,
        $status,
        $accountId,
        $envId;

    /**
     *
     * @return Scalr_SchedulerTask
     */
    public static function init($className = null)
    {
        return parent::init();
    }

    public static function getTypeByName($name)
    {
        switch($name) {
            case self::SCRIPT_EXEC:
                return "Execute script";
            case self::TERMINATE_FARM:
                return "Terminate farm";
            case self::LAUNCH_FARM:
                return "Launch farm";
        }
    }

    public function updateLastStartTime()
    {
        $this->lastStartTime = date('Y-m-d H:i:s');
        $this->db->Execute("UPDATE scheduler SET last_start_time = ? WHERE id = ?", [$this->lastStartTime, $this->id]);
    }

    /**
     * Checks whether this task was executed recently
     *
     * @return boolean Returns TRUE if either the task was executed less than 30 seconds ago or
     *                 it has never been executed.
     */
    public function isExecutedRecently()
    {
        //Last start time less than 30 seconds
        return $this->lastStartTime && (time() - strtotime($this->lastStartTime)) < 30;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function execute($manual = false)
    {
        $farmRoleNotFound = false;
        $logger = $this->getLogger() ?: Logger::getLogger(__CLASS__);

        switch($this->type) {
            case self::LAUNCH_FARM:
                try {
                    $farmId = $this->targetId;
                    $DBFarm = DBFarm::LoadByID($farmId);

                    if ($DBFarm->Status == FARM_STATUS::TERMINATED) {
                        // launch farm
                        Scalr::FireEvent($farmId, new FarmLaunchedEvent(true));
                        $logger->info(sprintf("Farm #{$farmId} successfully launched"));
                    } elseif($DBFarm->Status == FARM_STATUS::RUNNING) {
                        // farm is running
                        $logger->info(sprintf("Farm #{$farmId} is already running"));
                    } else {
                        // farm can't be launched
                        $logger->info(sprintf("Farm #{$farmId} can't be launched because of it's status: {$DBFarm->Status}"));
                    }
                } catch(Exception $e) {
                    $farmRoleNotFound  = true;
                    $logger->info(sprintf("Farm #{$farmId} was not found and can't be launched"));
                }
                break;

            case self::TERMINATE_FARM:
                try {
                    // get config settings
                    $farmId = $this->targetId;

                    $deleteDNSZones = (int)$this->config['deleteDNSZones'];
                    $deleteCloudObjects = (int)$this->config['deleteCloudObjects'];
                    $keepCloudObjects = $deleteCloudObjects == 1 ? 0 : 1;

                    $DBFarm = DBFarm::LoadByID($farmId);

                    if($DBFarm->Status == FARM_STATUS::RUNNING) {
                        // terminate farm
                        $event = new FarmTerminatedEvent($deleteDNSZones, $keepCloudObjects, false, $keepCloudObjects);
                        Scalr::FireEvent($farmId, $event);

                        $logger->info(sprintf("Farm successfully terminated"));
                    } else {
                        $logger->info(sprintf("Farm #{$farmId} can't be terminated because of it's status"));
                    }
                } catch(Exception $e) {
                    $farmRoleNotFound  = true;
                    $logger->info(sprintf("Farm #{$farmId} was not found and can't be terminated"));
                }
                break;

            case self::SCRIPT_EXEC:
                // generate event name
                $eventName = "Scheduler (TaskID: {$this->id})";
                if ($manual)
                    $eventName .= ' (manual)';

                try {
                    if (! \Scalr\Model\Entity\Script::findPk($this->config['scriptId']))
                        throw new Exception('Script not found');

                    // get executing object by target_type variable
                    switch($this->targetType) {
                        case self::TARGET_FARM:
                            $DBFarm = DBFarm::LoadByID($this->targetId);
                            $farmId = $DBFarm->ID;
                            $farmRoleId = null;

                            $servers = $this->db->GetAll("SELECT server_id FROM servers WHERE `status` IN (?,?) AND farm_id = ?",
                                array(SERVER_STATUS::INIT, SERVER_STATUS::RUNNING, $farmId)
                            );
                            break;

                        case self::TARGET_ROLE:
                            $farmRoleId = $this->targetId;
                            $servers = $this->db->GetAll("SELECT server_id FROM servers WHERE `status` IN (?,?) AND farm_roleid = ?",
                                array(SERVER_STATUS::INIT, SERVER_STATUS::RUNNING, $farmRoleId)
                            );
                            break;

                        case self::TARGET_INSTANCE:
                            $servers = $this->db->GetAll("SELECT server_id FROM servers WHERE `status` IN (?,?) AND farm_roleid = ? AND `index` = ? ",
                                array(SERVER_STATUS::INIT, SERVER_STATUS::RUNNING, $this->targetId, $this->targetServerIndex)
                            );
                            break;
                    }

                    if ($servers) {
                        $scriptSettings = array(
                            'version' => $this->config['scriptVersion'],
                            'scriptid' => $this->config['scriptId'],
                            'timeout' => $this->config['scriptTimeout'],
                            'issync' => $this->config['scriptIsSync'],
                            'params' => serialize($this->config['scriptOptions']),
                            'type' => Scalr_Scripting_Manager::ORCHESTRATION_SCRIPT_TYPE_SCALR
                        );

                        // send message to start executing task (starts script)
                        foreach ($servers as $server) {
                            $DBServer = DBServer::LoadByID($server['server_id']);

                            $msg = new Scalr_Messaging_Msg_ExecScript($eventName);
                            $msg->setServerMetaData($DBServer);

                            $script = Scalr_Scripting_Manager::prepareScript($scriptSettings, $DBServer);

                            $itm = new stdClass();
                            // Script
                            $itm->asynchronous = ($script['issync'] == 1) ? '0' : '1';
                            $itm->timeout = $script['timeout'];

                            if ($script['body']) {
                                $itm->name = $script['name'];
                                $itm->body = $script['body'];
                            } else {
                                $itm->path = $script['path'];
                            }
                            $itm->executionId = $script['execution_id'];

                            $msg->scripts = array($itm);
                            $msg->setGlobalVariables($DBServer, true);

                            /*
                            if ($DBServer->IsSupported('2.5.12')) {
                                $api = $DBServer->scalarizr->system;
                                $api->timeout = 5;

                                $api->executeScripts(
                                    $msg->scripts,
                                    $msg->globalVariables,
                                    $msg->eventName,
                                    $msg->roleName
                                );
                            } else
                            */
                            $DBServer->SendMessage($msg, false, true);
                        }
                    } else {
                        $farmRoleNotFound = true;
                    }
                } catch (Exception $e) {
                    // farm or role not found.
                    $farmRoleNotFound  = true;
                    $logger->warn(sprintf("Farm, role or instances were not found, script can't be executed"));
                }
                break;
        }

        return !$farmRoleNotFound;
    }
}
