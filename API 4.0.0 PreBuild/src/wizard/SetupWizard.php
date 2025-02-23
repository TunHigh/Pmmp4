<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

/**
 * Set-up wizard used on the first run
 * Can be disabled with --no-wizard
 */
namespace pocketmine\wizard;

use pocketmine\data\java\GameModeIdMap;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\lang\Language;
use pocketmine\lang\LanguageNotFoundException;
use pocketmine\player\GameMode;
use pocketmine\utils\Config;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetException;
use pocketmine\VersionInfo;
use Webmozart\PathUtil\Path;
use function fgets;
use function sleep;
use function strtolower;
use function trim;
use const PHP_EOL;
use const STDIN;

class SetupWizard{
	public const DEFAULT_NAME = VersionInfo::NAME . " Server";
	public const DEFAULT_PORT = 19132;
	public const DEFAULT_PLAYERS = 20;

	/** @var Language */
	private $lang;
	/** @var string */
	private $dataPath;

	public function __construct(string $dataPath){
		$this->dataPath = $dataPath;
	}

	public function run() : bool{
		$this->message(VersionInfo::NAME . " set-up wizard");

		try{
			$langs = Language::getLanguageList();
		}catch(LanguageNotFoundException $e){
			$this->error("No language files found, please use provided builds or clone the repository recursively.");
			return false;
		}

		$this->message("Please select a language");
		foreach($langs as $short => $native){
			$this->writeLine(" $native => $short");
		}

		do{
			$lang = strtolower($this->getInput("Language", "eng"));
			if(!isset($langs[$lang])){
				$this->error("Couldn't find the language");
				$lang = null;
			}
		}while($lang === null);

		$this->lang = new Language($lang);

		$this->message($this->lang->get(KnownTranslationKeys::LANGUAGE_HAS_BEEN_SELECTED));

		if(!$this->showLicense()){
			return false;
		}

		//this has to happen here to prevent user avoiding agreeing to license
		$config = new Config(Path::join($this->dataPath, "server.properties"), Config::PROPERTIES);
		$config->set("language", $lang);
		$config->save();

		if(strtolower($this->getInput($this->lang->get(KnownTranslationKeys::SKIP_INSTALLER), "n", "y/N")) === "y"){
			$this->printIpDetails();
			return true;
		}

		$this->writeLine();
		$this->welcome();
		$this->generateBaseConfig();
		$this->generateUserFiles();

		$this->networkFunctions();
		$this->printIpDetails();

		$this->endWizard();

		return true;
	}

	private function showLicense() : bool{
		$this->message($this->lang->translateString(KnownTranslationKeys::WELCOME_TO_POCKETMINE, [VersionInfo::NAME]));
		echo <<<LICENSE

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU Lesser General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

LICENSE;
		$this->writeLine();
		if(strtolower($this->getInput($this->lang->get(KnownTranslationKeys::ACCEPT_LICENSE), "n", "y/N")) !== "y"){
			$this->error($this->lang->translateString(KnownTranslationKeys::YOU_HAVE_TO_ACCEPT_THE_LICENSE, [VersionInfo::NAME]));
			sleep(5);

			return false;
		}

		return true;
	}

	private function welcome() : void{
		$this->message($this->lang->get(KnownTranslationKeys::SETTING_UP_SERVER_NOW));
		$this->message($this->lang->get(KnownTranslationKeys::DEFAULT_VALUES_INFO));
		$this->message($this->lang->get(KnownTranslationKeys::SERVER_PROPERTIES));
	}

	private function generateBaseConfig() : void{
		$config = new Config(Path::join($this->dataPath, "server.properties"), Config::PROPERTIES);

		$config->set("motd", ($name = $this->getInput($this->lang->get(KnownTranslationKeys::NAME_YOUR_SERVER), self::DEFAULT_NAME)));
		$config->set("server-name", $name);

		$this->message($this->lang->get(KnownTranslationKeys::PORT_WARNING));

		do{
			$port = (int) $this->getInput($this->lang->get(KnownTranslationKeys::SERVER_PORT), (string) self::DEFAULT_PORT);
			if($port <= 0 or $port > 65535){
				$this->error($this->lang->get(KnownTranslationKeys::INVALID_PORT));
				continue;
			}

			break;
		}while(true);
		$config->set("server-port", $port);

		$this->message($this->lang->get(KnownTranslationKeys::GAMEMODE_INFO));

		do{
			$gamemode = (int) $this->getInput($this->lang->get(KnownTranslationKeys::DEFAULT_GAMEMODE), (string) GameModeIdMap::getInstance()->toId(GameMode::SURVIVAL()));
		}while($gamemode < 0 or $gamemode > 3);
		$config->set("gamemode", $gamemode);

		$config->set("max-players", (int) $this->getInput($this->lang->get(KnownTranslationKeys::MAX_PLAYERS), (string) self::DEFAULT_PLAYERS));

		$config->save();
	}

	private function generateUserFiles() : void{
		$this->message($this->lang->get(KnownTranslationKeys::OP_INFO));

		$op = strtolower($this->getInput($this->lang->get(KnownTranslationKeys::OP_WHO), ""));
		if($op === ""){
			$this->error($this->lang->get(KnownTranslationKeys::OP_WARNING));
		}else{
			$ops = new Config(Path::join($this->dataPath, "ops.txt"), Config::ENUM);
			$ops->set($op, true);
			$ops->save();
		}

		$this->message($this->lang->get(KnownTranslationKeys::WHITELIST_INFO));

		$config = new Config(Path::join($this->dataPath, "server.properties"), Config::PROPERTIES);
		if(strtolower($this->getInput($this->lang->get(KnownTranslationKeys::WHITELIST_ENABLE), "n", "y/N")) === "y"){
			$this->error($this->lang->get(KnownTranslationKeys::WHITELIST_WARNING));
			$config->set("white-list", true);
		}else{
			$config->set("white-list", false);
		}
		$config->save();
	}

	private function networkFunctions() : void{
		$config = new Config(Path::join($this->dataPath, "server.properties"), Config::PROPERTIES);
		$this->error($this->lang->get(KnownTranslationKeys::QUERY_WARNING1));
		$this->error($this->lang->get(KnownTranslationKeys::QUERY_WARNING2));
		if(strtolower($this->getInput($this->lang->get(KnownTranslationKeys::QUERY_DISABLE), "n", "y/N")) === "y"){
			$config->set("enable-query", false);
		}else{
			$config->set("enable-query", true);
		}

		$config->save();
	}

	private function printIpDetails() : void{
		$this->message($this->lang->get(KnownTranslationKeys::IP_GET));

		$externalIP = Internet::getIP();
		if($externalIP === false){
			$externalIP = "unknown (server offline)";
		}
		try{
			$internalIP = Internet::getInternalIP();
		}catch(InternetException $e){
			$internalIP = "unknown (" . $e->getMessage() . ")";
		}

		$this->error($this->lang->translateString(KnownTranslationKeys::IP_WARNING, ["EXTERNAL_IP" => $externalIP, "INTERNAL_IP" => $internalIP]));
		$this->error($this->lang->get(KnownTranslationKeys::IP_CONFIRM));
		$this->readLine();
	}

	private function endWizard() : void{
		$this->message($this->lang->get(KnownTranslationKeys::YOU_HAVE_FINISHED));
		$this->message($this->lang->get(KnownTranslationKeys::POCKETMINE_PLUGINS));
		$this->message($this->lang->translateString(KnownTranslationKeys::POCKETMINE_WILL_START, [VersionInfo::NAME]));

		$this->writeLine();
		$this->writeLine();

		sleep(4);
	}

	private function writeLine(string $line = "") : void{
		echo $line . PHP_EOL;
	}

	private function readLine() : string{
		return trim((string) fgets(STDIN));
	}

	private function message(string $message) : void{
		$this->writeLine("[*] " . $message);
	}

	private function error(string $message) : void{
		$this->writeLine("[!] " . $message);
	}

	private function getInput(string $message, string $default = "", string $options = "") : string{
		$message = "[?] " . $message;

		if($options !== "" or $default !== ""){
			$message .= " (" . ($options === "" ? $default : $options) . ")";
		}
		$message .= ": ";

		echo $message;

		$input = $this->readLine();

		return $input === "" ? $default : $input;
	}
}
