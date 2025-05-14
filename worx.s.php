<?php
declare(strict_types=1);

use parallel\{Channel,Runtime,Events,Events\Event};

$restThread = function(string $scriptId, string $nodeId, array $worxSettings, Channel $homegearChannel){
    require('worx.classes.php'); //Bootstrapping in Runtime constructor does not work for some reason.
    $hg = new \Homegear\Homegear();
    if($hg->registerThread($scriptId) === false){
        $hg->log(2, "Could not register thread.");
        return;
    }
    
    $settings = new WorxSettings();
    $settings->email = $worxSettings['email'];
    $settings->password = $worxSettings['password'];
    $settings->clientId = '013132A8-DB34-4101-B993-3C8348EA0EBC';
    $settings->nodeId = $nodeId;

    $worxRest = new WorxRest($settings);
    
    $events = new Events();
    $events->addChannel($homegearChannel);
    $events->setTimeout(100000);

    $i = 0;
    $nextTo = rand(50, 70);

    while(true){
        try{
            if($i < $nextTo){
                $breakLoop = false;
                $event = NULL;
                do{
                    $event = $events->poll();
                    if($event){
                        if($event->source == 'mainHomegearChannelNode'.$nodeId){
                            $events->addChannel($homegearChannel);
                            if($event->type == Event\Type::Read){
                                if(is_array($event->value) && count($event->value) > 0){
                                    if($event->value['name'] == 'stop') $breakLoop = true; //Stop
                                    elseif($event->value['name'] == 'getProductItems') $worxRest->getProductItems();
                                    elseif($event->value['name'] == 'setValue') $worxRest->setValue($event->value['value']);
                                }
                            }
                            else if($event->type == Event\Type::Close) $breakLoop = true; //Stop
                        }
                    }
                    if($breakLoop) break;
                }
                while($event);
            }

            if($breakLoop){
                $worxRest->logout();
                break;
            }
            if($i < $nextTo) continue;
            $i = 0;
            $nextTo = rand(50, 70);

            $worxRest->checkToken();
        }
        catch(Events\Error\Timeout $ex){
            $i++;
        }
    }
};

class HomegearNode extends HomegearNodeBase
{
    private $hg = NULL;
    private $nodeInfo = NULL;
    private $mainRuntime = NULL;
    private $mainFuture = NULL;
    private $mainHomegearChannel = NULL; //Channel to pass Homegear events to main thread

    function __construct(){
        $this->hg = new \Homegear\Homegear();
    }

    function __destruct(){
        $this->stop();
        $this->waitForStop();
    }

    public function init(array $nodeInfo) : bool{
        $this->nodeInfo = $nodeInfo;
        return true;
    }

    public function start() : bool{
        HomegearNodeBase::log(4, "START");
        $scriptId = $this->hg->getScriptId();
        $nodeId = $this->nodeInfo['id'];

        $worxSettings = array();
        $worxSettings['email'] = $this->nodeInfo['info']['email'];
        $worxSettings['password'] = $this->getNodeData('user-password');
        $this->mainRuntime = new Runtime();
        $this->mainHomegearChannel = Channel::make('mainHomegearChannelNode'.$nodeId, Channel::Infinite);
        global $restThread;
        $this->mainFuture = $this->mainRuntime->run($restThread, [$scriptId, $nodeId, $worxSettings, $this->mainHomegearChannel]);
        HomegearNodeBase::log(4, gettype($this->mainFuture));
        HomegearNodeBase::log(4, gettype($restThread));
       
        return true;
    }

    public function input(array $nodeInfoLocal, int $inputIndex, array $message){
        if($this->mainHomegearChannel){
            if($inputIndex == 0) $this->mainHomegearChannel->send(['name' => 'getProductItems', 'value' => true]);
            elseif($inputIndex == 1) $this->mainHomegearChannel->send(['name' => 'setValue', 'value' => $message['payload']]);
        }
    }

    public function stop(){
        if($this->mainHomegearChannel) $this->mainHomegearChannel->send(['name' => 'stop', 'value' => true]);
    }

    public function waitForStop(){
        if($this->mainFuture){
            $this->mainFuture->value();
            $this->mainFuture = NULL;
        }

        if($this->mainHomegearChannel){
            $this->mainHomegearChannel->close();
            $this->mainHomegearChannel = NULL;
        }

        if($this->mainRuntime){
            $this->mainRuntime->close();
            $this->mainRuntime = NULL;
        }
    }
}
