<?php

namespace specter\network;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\RespawnPacket;
use pocketmine\network\mcpe\protocol\SetHealthPacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\SourceInterface;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use pocketmine\utils\UUID;
use specter\Specter;

class SpecterInterface implements SourceInterface{
    /** @var  SpecterPlayer[]|\SplObjectStorage */
    private $sessions;
    /** @var  Specter */
    private $specter;
    /** @var  array */
    private $ackStore;
    /** @var  array */
    private $replyStore;

    public function __construct(Specter $specter){
        $this->specter = $specter;
        $this->sessions = new \SplObjectStorage();
        $this->ackStore = [];
        $this->replyStore = [];
    }

    public function start(){
        //NOOP
    }

    /**
     * Sends a DataPacket to the interface, returns an unique identifier for the packet if $needACK is true
     *
     * @param Player $player
     * @param DataPacket $packet
     * @param bool $needACK
     * @param bool $immediate
     *
     * @return int
     */
    public function putPacket(Player $player, DataPacket $packet, bool $needACK = false, bool $immediate = true){
        if($player instanceof SpecterPlayer){
            //$this->specter->getLogger()->info(get_class($packet));
            switch(get_class($packet)){
                case ResourcePacksInfoPacket::class:
                    $pk = new ResourcePackClientResponsePacket();
                    $pk->status = ResourcePackClientResponsePacket::STATUS_COMPLETED;
                    $this->sendPacket($player, $pk);
                    break;
                case TextPacket::class:
                    /** @var TextPacket $packet */
                    $type = "Unknown";
                    switch($packet->type){
                        case TextPacket::TYPE_CHAT:
                            $type = "Chat"; // warn about deprecation?
                            break;
                        case TextPacket::TYPE_RAW:
                            $type = "Message";
                            break;
                        case TextPacket::TYPE_POPUP:
                            $type = "Popup";
                            break;
                        case TextPacket::TYPE_TIP:
                            $type = "Tip";
                            break;
                        case TextPacket::TYPE_TRANSLATION:
                            $type = "Translation (with params: " . implode(", ", $packet->parameters) . ")";
                            break;
                    }
                    $this->specter->getLogger()->info(TextFormat::LIGHT_PURPLE . "$type to {$player->getName()}: " . TextFormat::WHITE . $packet->message);
                    break;
                case SetHealthPacket::class:
                    /** @var SetHealthPacket $packet */
                    if($packet->health <= 0){
                        if($this->specter->getConfig()->get("autoRespawn")){
                            $pk = new RespawnPacket();
                            $this->replyStore[$player->getName()][] = $pk;
                        }
                    }else{
                        $player->spec_needRespawn = true;
                    }
                    break;
                case StartGamePacket::class:
                    $pk = new RequestChunkRadiusPacket();
                    $pk->radius = 8;
                    $this->replyStore[$player->getName()][] = $pk;
                    break;
                case PlayStatusPacket::class:
                    /** @var PlayStatusPacket $packet */
                    switch($packet->status){
                        case PlayStatusPacket::PLAYER_SPAWN:
                            /*$pk = new MovePlayerPacket();
                            $pk->x = $player->getPosition()->x;
                            $pk->y = $player->getPosition()->y;
                            $pk->z = $player->getPosition()->z;
                            $pk->yaw = $player->getYaw();
                            $pk->pitch = $player->getPitch();
                            $pk->bodyYaw = $player->getYaw();
                            $pk->onGround = true;
                            $pk->handle($player);*/
                            break;
                    }
                    break;
                case MovePlayerPacket::class:
                    /** @var MovePlayerPacket $packet */
                    $eid = $packet->entityRuntimeId;
                    if($eid === $player->getId() && $player->isAlive() && $player->spawned === true && $player->getForceMovement() !== null){
                        $packet->mode = MovePlayerPacket::MODE_NORMAL;
                        $packet->yaw += 25; //FIXME little hacky
                        $this->replyStore[$player->getName()][] = $packet;
                    }
                    break;
                case BatchPacket::class:
                    /** @var BatchPacket $packet */
                    $packet->offset = 1;
                    $packet->decode();

                    foreach($packet->getPackets() as $buf){
                        $pk = PacketPool::getPacketById(ord($buf{0}));
                        //$this->specter->getLogger()->info("PACK:" . get_class($pk));
                        if(!$pk->canBeBatched()){
                            throw new \InvalidArgumentException("Received invalid " . get_class($pk) . " inside BatchPacket");
                        }

                        $pk->setBuffer($buf, 1);
                        $this->putPacket($player, $pk, false, $immediate);
                    }
                    break;
                case SetTitlePacket::class:
                    /** @var SetTitlePacket $packet */
                    $this->specter->getLogger()->info(TextFormat::LIGHT_PURPLE . "Title to {$player->getName()}: " . TextFormat::WHITE . $packet->text);
                    break;
                case ModalFormRequestPacket::class:
                    /** @var ModalFormRequestPacket $packet */
                    $data = json_decode($packet->formData);
                    $this->specter->getLogger()->info(sprintf('[FORM#%3$d] %1$s received %2$s:', $player->getName(), $data->type, $packet->formId));
                    if($data->type === "modal"){
                        $this->specter->getLogger()->info(sprintf('[FORM#%d] Title: %s', $packet->formId, $data->title));
                        $this->specter->getLogger()->info(sprintf('[FORM#%d] Content: %s', $packet->formId, $data->content));
                        $this->specter->getLogger()->info(sprintf('[FORM#%1$d] %2$s => /s f %1$d true', $packet->formId, $data->button1));
                        $this->specter->getLogger()->info(sprintf('[FORM#%1$d] %2$s => /s f %1$d false', $packet->formId, $data->button2));
                    }elseif($data->type === "form"){
                        $this->specter->getLogger()->info(sprintf('[FORM#%d] Title: %s', $packet->formId, $data->title));
                        $this->specter->getLogger()->info(sprintf('[FORM#%d] Content: %s', $packet->formId, $data->content));
                        $this->specter->getLogger()->info(sprintf('[FORM#%1$d] Close => /s f %1$d null', $packet->formId));
                        foreach($data->buttons as $i => $button){
                            $this->specter->getLogger()->info(sprintf('[FORM#%1$d] Option %3$s %4$d=> /s f %1$d %2$d',
                                $packet->formId, $i, $button->text,
                                isset($button->image) ? "{$button->image->type}:{$button->image->data} " : ""));
                        }
                    }elseif($data->type === "custom_form"){
                        $this->specter->getLogger()->info(sprintf('[FORM#%d] Title: %s', $packet->formId, $data->title));
                        $this->specter->getLogger()->info(sprintf('[FORM#%d] [', $packet->formId));
                        foreach($data->content as $element){
                            switch($element->type){
                                case "label":
                                    $details = "Label: $element->text => null";
                                    break;
                                case "toggle":
                                    $details = "Toggle: $element->text => true | false = " . (($element->default ?? false) ? "true" : "false");
                                    break;
                                case "slider":
                                    $details = "Slider: $element->text => $element->min-$element->max" . (isset($element->step) ? " (step: $element->step) " : "") . (isset($element->default) ? " = $element->default" : "");
                                    break;
                                case "step_slider":
                                    $details = "Step Slider: $element->text => ";
                                    $steps = [];
                                    foreach($element->steps as $i => $step){
                                        $steps[] = "$step => $i";
                                    }
                                    $details .= implode(", ", $steps);
                                    if(isset($element->default)){
                                        $details[] = " = $element->default";
                                    }
                                    break;
                                case "dropdown":
                                    $details = "Dropdown: $element->text => ";
                                    $options = [];
                                    foreach($element->options as $i => $option){
                                        $options[] = "$option => $i";
                                    }
                                    $details .= implode(", ", $options);
                                    if(isset($element->default)){
                                        $details[] = " = $element->default";
                                    }
                                    break;
                                case "input":
                                    $details = "Input: $element->text ($element->placeholder) " . (isset($element->default) ? " = $element->default" : "");
                                    break;
                                default:
                                    $details = "Unknown element: " . json_encode($element);
                                    break;
                            }
                            $this->specter->getLogger()->info(sprintf('[FORM#%d]     %s ,', $packet->formId, $details));
                        }
                        $this->specter->getLogger()->info(sprintf('[FORM#%d] ]', $packet->formId));
                    }
                    break;
            }
            if($needACK){
                $id = count($this->ackStore[$player->getName()]);
                $this->ackStore[$player->getName()][] = $id;
                $this->specter->getLogger()->info("Created ACK.");
                return $id;
            }
        }
        return null;
    }

    /**
     * Terminates the connection
     *
     * @param Player $player
     * @param string $reason
     *
     */
    public function close(Player $player, string $reason = "unknown reason"){
        $this->sessions->detach($player);
        unset($this->ackStore[$player->getName()]);
        unset($this->replyStore[$player->getName()]);
    }

    /**
     * @param string $name
     */
    public function setName(string $name){
        // TODO: Implement setName() method.
    }

    public function openSession($username, $address = "SPECTER", $port = 19133){
        if(!isset($this->replyStore[$username])){
            $player = new SpecterPlayer($this, $address, $port);
            $this->sessions->attach($player, $username);
            $this->ackStore[$username] = [];
            $this->replyStore[$username] = [];
            $this->specter->getServer()->addPlayer($player);

            $pk = new class() extends LoginPacket{
                public function decodeAdditional(){
                }
            };
            $pk->username = $username;
            $pk->protocol = ProtocolInfo::CURRENT_PROTOCOL;
            $pk->clientUUID = UUID::fromData($address, $port, $username)->toString();
            $pk->clientId = 1;
            $pk->xuid = "xuid here";
            $pk->identityPublicKey = "key here";
            $pk->clientData["SkinId"] = "Specter";
            $pk->clientData["SkinData"] = base64_encode(str_repeat("\x80", 64 * 32 * 4));
            $pk->skipVerification = true;

            $this->sendPacket($player, $pk);

            return true;
        }else{
            return false;
        }
    }

    public function process() : void{
        foreach($this->ackStore as $name => $acks){
            $player = $this->specter->getServer()->getPlayer($name);
            if($player instanceof SpecterPlayer){
                /** @noinspection PhpUnusedLocalVariableInspection */
                foreach($acks as $id){

                    //$player->handleACK($id); // TODO method removed. Though, Specter shouldn't have ACK to fill.
                    $this->specter->getLogger()->info("Filled ACK.");
                }
            }
            $this->ackStore[$name] = [];
        }
        /**
         * @var string $name
         * @var DataPacket[] $packets
         */
        foreach($this->replyStore as $name => $packets){
            $player = $this->specter->getServer()->getPlayer($name);
            if($player instanceof SpecterPlayer){
                foreach($packets as $pk){
                    $this->sendPacket($player, $pk);
                }
            }
            $this->replyStore[$name] = [];
        }
    }

    public function queueReply(DataPacket $pk, $player){
        $this->replyStore[$player][] = $pk;
    }

    public function shutdown(){
        // TODO: Implement shutdown() method.
    }

    public function emergencyShutdown(){
        // TODO: Implement emergencyShutdown() method.
    }

    private function sendPacket(SpecterPlayer $player, DataPacket $packet){
        $this->specter->getServer()->getPluginManager()->callEvent($ev = new DataPacketReceiveEvent($player, $packet));
        if(!$ev->isCancelled()){
            $packet->handle($player->getSessionAdapter());
        }
    }
}
