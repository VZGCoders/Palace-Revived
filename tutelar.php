<?php

/*
 * This file is a part of the Tutelar project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Tutelar;

class Tutelar
{
    public $loop;
    public $discord;
    public $twitch;
    public $browser;
    public $logger;
    public $stats;
    public $mysqli = [];
    public $pdo = [];
    public $filecache = false;
    public $filecache_path = '';
    public $filecache_prefix = '';
    
    protected $webapi;
    protected $webauth;
    
    public $timers = [];
    
    public $functions = [];
    
    public $command_symbol = [];
    public $owner_id = '116927250145869826';
    public $owner_guild_id = '923969098185068594';
    
    public $files = [];
    public $ips = [];
    public $ports = [];
    public $channel_ids = [];
    public $role_ids = [];
    
    public $discord_config = [];
    public $suggestions = [];
    public $tips = [];
    public $tests = [];
    
    /**
     * Creates a Tutelar client instance.
     *
     * @param  array           $options Array of options.
     * @throws IntentException
     */
    public function __construct(array $options = [])
    {
        if (php_sapi_name() !== 'cli') {
            trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);
        }

        // x86 need gmp extension for big integer operation
        if (PHP_INT_SIZE === 4 && ! \Discord\Helpers\BigInt::init()) {
            trigger_error('ext-gmp is not loaded. Permissions will NOT work correctly!', E_USER_WARNING);
        }
        
        $options = $this->resolveOptions($options);
        
        $this->loop = $options['loop'];
        $this->browser = $options['browser'];
        $this->logger = $options['logger'];
        $this->stats = $options['stats'];
        
        if (isset($options['filecache_path'])) {
            if (is_string($options['filecache_path'])) $this->filecache_path = $options['filecache_path'];
            else $this->filecache_path = getcwd() . '/json/';
        } else $this->filecache_path = getcwd() . '/json/';
        if (!file_exists($this->filecache_path)) mkdir($this->filecache_path, 0664, true);
        
        if (isset($options['filecache_prefix']) && is_string($options['filecache_prefix'])) $this->filecache_path = $options['filecache_prefix'];
        
        if (isset($options['mysqli'])) {
            if (is_array($options['mysqli'])) foreach ($options['mysqli'] as $con) $this->mysqli[] = $con;
            else $this->mysqli[] = $options['mysqli'];
        }
        
        if (isset($options['pdo'])) {
            if (is_array($options['pdo'])) foreach ($options['pdo'] as $pdo) $this->pdo[] = $pdo;
            else $this->pdo[] = $options['pdo'];
        }
        
        if (isset($options['command_symbol'])) {
            if (is_array($options['command_symbol'])) foreach ($options['command_symbol'] as $symbol) $this->command_symbol[] = $symbol;
            elseif (is_string($options['command_symbol'])) $this->command_symbol[] = $options['command_symbol'];
        }
        if (isset($options['owner_id'])) $this->owner_id = $options['owner_id'];
        if (isset($options['owner_guild_id'])) $this->owner_guild_id = $options['owner_guild_id'];
        if (isset($options['github'])) $this->github = $options['github'];
        
        if (isset($options['discord'])) $this->discord = $options['discord'];
        elseif (isset($options['discord_options'])) $this->discord = new \Discord\Discord($options['discord_options']);
        if (isset($options['twitch']) && $options['twitch'] instanceof \Twitch\Twitch) $this->twitch = $options['twitch'];
        elseif (isset($options['twitch_options'])) $this->twitch = new \Twitch\Twitch($options['twitch_options']);
        
        if (isset($options['functions'])) foreach ($options['functions'] as $key1 => $key2) foreach ($options['functions'][$key1] as $key3 => $func) $this->functions[$key1][$key3] = $func;
        else $this->logger->warning('No functions passed in options!');
        if (isset($options['files'])) foreach ($options['files'] as $key => $path) $this->files[$key] = $path;
        else $this->logger->warning('No files passed in options!');
        $this->afterConstruct();
    }
    
    protected function afterConstruct() : void
    {
        
        if (isset($this->discord)) {
            $this->discord->once('ready', function () {
                //Initialize configurations
                if (! $discord_config = $this->VarLoad('discord_config.json')) $discord_config = [];
                foreach ($this->discord->guilds as $guild) if (!isset($discord_config[$guild->id])) $this->SetConfigTemplate($guild, $discord_config);
                $this->discord_config = $discord_config;
                
                if (! $suggestions = $this->VarLoad('suggestions.json')) $suggestions = [];
                foreach ($this->discord->guilds as $guild) if (!isset($suggestions[$guild->id])) $suggestions[$guild->id] = ['pending' => [], 'approved' => [], 'denied' => []];
                $this->suggestions = $suggestions;
                
                if (! $tips = $this->VarLoad('tips.json')) $tips = [];
                $this->tips = $tips;
                
                if (! $tests = $this->VarLoad('tests.json')) $tests = [];
                $this->tests = $tests;
                
                $this->command_symbol[] = '<@'.$this->discord->id.'>';
                $this->command_symbol[] = '<@!'.$this->discord->id.'>';
                
                if (!empty($this->functions['ready'])) foreach ($this->functions['ready'] as $key => $func) $func($this);
                else $this->logger->debug('No ready functions found!');
                
                $this->discord->application->commands->freshen()->done(function ($commands) {
                    if (!empty($this->functions['ready_slash'])) foreach ($this->functions['ready_slash'] as $key => $func) $func($this, $commands);
                    else $this->logger->debug('No ready slash functions found!');
                });
                
                //Initialize event listeners
                $this->InitializeListeners();
            });
        }
    }
    
    /*
    * Attempt to catch errors with the user-provided $options early
    */
    protected function resolveOptions(array $options = []): array
    {
        if (is_null($options['logger'])) {
            $logger = new \Monolog\Logger('Tutelar');
            $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::DEBUG));
            $options['logger'] = $logger;
        }
        
        $options['loop'] = $options['loop'] ?? \React\EventLoop\Loop::get();
        $options['browser'] = $options['browser'] ?? new \React\Http\Browser($options['loop']);
        return $options;
    }
    
    public function run(): void
    {
        $this->logger->info('Starting Twitch loop');
        if (!(isset($this->twitch))) $this->logger->warning('Twitch not set!');
		else $this->twitch->run();
        $this->logger->info('Starting Discord loop');
        if (!(isset($this->discord))) $this->logger->warning('Discord not set!');
        else $this->discord->run();
    }
    
    public function stop(): void
    {
        $this->logger->info('Shutting down');
        if ((isset($this->discord))) $this->discord->stop();
    }
    
    public function setWebAPI(\React\Http\Server $webapi): void
    {
        $this->webapi = $webapi;
    }
    
    public function setWebAuth($webauth): void //NYI
    {
        $this->webauth = $webauth;
    }
    
    /*
     * Please maintain a consistent schema for directories and files
     *
     * Tutelar's $filecache_path should be a folder named json inside of either cwd() or __DIR__
     * getcwd() should be used if there are multiple instances of this bot operating from different source directories or on different shards but share the same bot files (NYI)
     * __DIR__ should be used if the json folder should be expected to always be in the same folder as this file, but only if this bot is not installed inside of /vendor/
     *
     * The recommended schema is to follow DiscordPHP's Redis schema, but replace : with ;
     * dphp:cache:Channel:115233111977099271:1001123612587212820 would become dphp;cache;Channel;115233111977099271;1001123612587212820.json
     * In the above example the first set numbers represents the guild_id and the second set of numbers represents the channel_id
     * Similarly, Messages might be cached like dphp;cache;Message;11523311197709927;234582138740146176;1014616396270932038.json where the third set of numbers represents the message_id
     * This schema is recommended because the expected max length of the file name will not usually exceed 80 characters, which is far below the NTFS character limit of 255,
     * and is still generic enough to easily automate saving and loading files using data served by Discord
     *
     * Windows users may need to enable long path in Windows depending on whether the length of the installation path would result in subdirectories exceeding 260 characters
     * Click Window key and type gpedit.msc, then press the Enter key. This launches the Local Group Policy Editor
     * Navigate to Local Computer Policy > Computer Configuration > Administrative Templates > System > Filesystem
     * Double click Enable NTFS long paths
     * Select Enabled, then click OK
     *
     * If using Windows 10/11 Home Edition, the following commands need to be used in an elevated command prompt before continuing with gpedit.msc
     * FOR %F IN ("%SystemRoot%\servicing\Packages\Microsoft-Windows-GroupPolicy-ClientTools-Package~*.mum") DO (DISM /Online /NoRestart /Add-Package:"%F")
     * FOR %F IN ("%SystemRoot%\servicing\Packages\Microsoft-Windows-GroupPolicy-ClientExtensions-Package~*.mum") DO (DISM /Online /NoRestart /Add-Package:"%F")
     */
    public function VarSave(string $filename = '', array $assoc_array = []): bool
    {
        if ($filename === '') return false;
        if (file_put_contents($this->filecache_path . $filename, json_encode($assoc_array)) === false) return false;
        return true;
    }

    public function VarLoad(string $filename = ''): false|array
    {
        if ($filename === '') return false;
        if (!file_exists($this->filecache_path . $filename)) return false;
        if (($string = file_get_contents($this->filecache_path . $filename)) === false) return false;
        if ($assoc_array = json_decode($string, TRUE)) return $assoc_array;
        return false;
    }

    public function SetConfigTemplate(\Discord\Parts\Guild\Guild $guild, array &$discord_config): void
    {
        $discord_config[$guild->id] = [
            'toggles' => [
                //'vanity'
                //'nsfw'
                'rolepicker' => true,
                'games' => true
            ],
            'roles' => [
                'verified' => '',
            ],
            'channels' => [
                'general' => '',
                'welcome' => '',
                'welcomelog' => '',
                'log' => '',
                'verify' => '',
                'watch' => '',
                'suggestion_pending' => '',
                'suggestion_approved' => '',
                'tip_pending' => '',
                'tip_approved' => '',
                
                //'rolepicker' => '',
                'games' => ''
            ]
        ];
        $discord_config[$guild->id]['reaction_roles']['species1'] =  [
            'default_color' => '15158332',
            'message_content' => 'Species [1 of 2]',
            'id' => '',
            'roles' => [
                [
                    'name' => 'Aquatic',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Avian',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Bat',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Bear',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Canine',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Deer',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Dolphin',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Dragon',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Equine',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Feline',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Fox',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Goat',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Human',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Hybrid',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Kangaroo',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Mobold',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Mouse',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Otter',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Panda',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Penguin',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ]
            ]
        ];
        $discord_config[$guild->id]['reaction_roles']['species2'] = [
            'default_color' => 15158332,
            'message_content' => 'Species [2 of 2]',
            'id' => '',
            'roles' => [
                [
                    'name' => 'Pokemon',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Protogen',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Rabbit',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Raccoon',
                    'emoji' => '???????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Reptile',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Robot',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Sergal',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Shapeshifter',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Shark',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Squirrel',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Wolf',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Misc/Unlisted Species',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ]
            ]
        ];
        $discord_config[$guild->id]['reaction_roles']['genders'] = [
            'default_color' => 7419512,
            'message_content' => 'Genders',
            'id' => '',
            'roles' => [
                [
                    'name' => 'Gender Fluid',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Non-Binary',
                    'emoji' => '???',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Female',
                    'emoji' => '??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Male',
                    'emoji' => '??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ]
            ]
        ];
        $discord_config[$guild->id]['reaction_roles']['pronouns'] = [
            'default_color' => 10181046,
            'message_content' => 'Pronouns',
            'id' => '',
            'roles' => [
                [
                    'name' => 'He/Him',
                    'emoji' => '1??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'She/Her',
                    'emoji' => '2??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'They/Them',
                    'emoji' => '3??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Ze/Zem',
                    'emoji' => '4??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Other',
                    'emoji' => '5??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Any Pronouns',
                    'emoji' => '6??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Ask For Pronouns',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'No Preference',
                    'emoji' => '???',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ]
            ]
        ];
        $discord_config[$guild->id]['reaction_roles']['sexualities'] = [
            'default_color' => 10038562,
            'message_content' => 'Sexualities',
            'id' => '',
            'roles' => [
                [
                    'name' => 'Straight',
                    'emoji' => '???????????????????????????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Questioning Sexuality',
                    'emoji' => '???',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Asexual',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Pansexual',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Demisexual',
                    'emoji' => '??????????????????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Bicurious',
                    'emoji' => '???',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Bi',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ],
                [
                    'name' => 'Gay/Lesbian',
                    'emoji' => '??????????????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ]
            ]
        ];
        $discord_config[$guild->id]['reaction_roles']['channels'] = [
            'default_color' => 1752220,
            'message_content' => 'Channels',
            'id' => '',
            'roles' => [
                [
                    'name' => 'Anime',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Art',
                    'emoji' => '???????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Memes',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Games',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Music',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Videos',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Screenshots',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Stories',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Code',
                    'emoji' => '??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                    
                ],
                [
                    'name' => 'Science',
                    'emoji' => '???????????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Politics',
                    'emoji' => '??????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ],
                [
                    'name' => 'Bot Commands',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                    
                ],
                [
                    'name' => 'Github',
                    'emoji' => '???????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0,
                    'id' => ''
                ]
            ]
        ];
        $discord_config[$guild->id]['reaction_roles']['adult'] = [
            'default_color' => 16711680,
            'message_content' => 'NSFW',
            'id' => '',
            'roles' => [
                [
                    'name' => '18+',
                    'emoji' => '????',
                    'color' => null,
                    'hoist' => false,
                    'mentionable' => false,
                    'permissions' => 0, 
                    'id' => ''
                ]
            ]
        ];
        $discord_config[$guild->id]['reaction_roles']['custom'] = [
            'default_color' => 0,
            'message_content' => 'Custom',
            'id' => '',
            'roles' => []
        ];
        if ($this->VarSave('discord_config.json', $discord_config)) $this->logger->info("Created new config for guild {$guild->name}");
        else $this->logger->warning("Failed top creat new config for guild {$guild->name}");
    }

    public function InitializeListeners(): void
    {
        //Finish ready and bot initialization
        $this->discord->on('GUILD_CREATE', function (\Discord\Parts\Guild\Guild $guild)
        {
            foreach ($this->discord->guilds as $guild) if (!isset($this->discord_config[$guild->id])) $this->SetConfigTemplate($guild, $this->discord_config);
        });
        $this->discord->on('MESSAGE_REACTION_ADD', function ($reaction) {
            if ($reaction->user_id == $this->discord->id) return; //Do not add roles to the bot
            if (is_null($reaction->message)) {
				$reaction->channel->messages->fetch($reaction->message_id)->done(function ($message) use ($reaction) {
					$this->roleReactionAdd($reaction);
				});
			} else $this->roleReactionAdd($reaction);
        });
        $this->discord->on('MESSAGE_REACTION_REMOVE', function (\Discord\Parts\WebSockets\MessageReaction $reaction) {
            if ($reaction->user_id == $this->discord->id) return; //Do not add roles to the bot
            if (is_null($reaction->message)) $reaction->channel->messages->fetch($reaction->message_id)->done(function ($message) use ($reaction) { $this->roleReactionRemove($reaction); });
			else $this->roleReactionRemove($reaction);
        });
        $this->discord->getLoop()->addPeriodicTimer(1800, function () { //Automatically save configurations every 6 hours if changes were made
            if ($this->discord_config != $this->VarLoad('discord_config.json')) $this->VarSave('discord_config.json', $this->discord_config);
        });
        
        
        
        if (!empty($this->functions['GUILD_CREATE'])) {
            $this->discord->on('GUILD_CREATE', function (\Discord\Parts\Guild\Guild $guild)
            {
                 foreach ($this->functions['GUILD_CREATE'] as $key => $func) $func($this, $guild);
            });
        } else $this->logger->debug('No GUILD_CREATE functions found!');
        
        if (!empty($this->functions['message'])) {
            $this->discord->on('message', function ($message)
            {
                foreach ($this->functions['message'] as $key => $func) $func($this, $message);
            });
        } else $this->logger->debug('No message functions found!');
        
        if (!empty($this->functions['MESSAGE_UPDATE'])) {
            $this->discord->on('MESSAGE_UPDATE', function ($message, \Discord\Discord $discord, $message_old)
            {
                 foreach ($this->functions['MESSAGE_UPDATE'] as $key => $func) $func($this, $message, $message_old);
            });
        } else $this->logger->debug('No MESSAGE_UPDATE functions found!');
        
        if (!empty($this->functions['MESSAGE_DELETE'])) {
            $this->discord->on('MESSAGE_DELETE', function ($message)
            {
                 foreach ($this->functions['MESSAGE_DELETE'] as $key => $func) $func($this, $message);
            });
        } else $this->logger->debug('No MESSAGE_DELETE functions found!');
        
        if (!empty($this->functions['MESSAGE_DELETE_BULK'])) {
            $this->discord->on('MESSAGE_DELETE_BULK', function ($messages)
            {
                 foreach ($this->functions['MESSAGE_DELETE_BULK'] as $key => $func) $func($this, $messages);
            });
        } else $this->logger->debug('No MESSAGE_DELETE_BULK functions found!');
        
        if (!empty($this->functions['GUILD_MEMBER_ADD'])) {
            $this->discord->on('GUILD_MEMBER_ADD', function (\Discord\Parts\User\Member $member)
            {
                 foreach ($this->functions['GUILD_MEMBER_ADD'] as $key => $func) $func($this, $member);
            });
        } else $this->logger->debug('No GUILD_MEMBER_ADD functions found!');
        
        if (!empty($this->functions['GUILD_MEMBER_REMOVE'])) {
            $this->discord->on('GUILD_MEMBER_REMOVE', function (\Discord\Parts\User\Member $member)
            {
                 foreach ($this->functions['GUILD_MEMBER_REMOVE'] as $key => $func) $func($this, $member);
            });
        } else $this->logger->debug('No GUILD_MEMBER_REMOVE functions found!');
        
        if (!empty($this->functions['GUILD_MEMBER_UPDATE'])) {
            $this->discord->on('GUILD_MEMBER_UPDATE', function (\Discord\Parts\User\Member $member, \Discord\Discord $discord, ?\Discord\Parts\User\Member $member_old)
            {
                 foreach ($this->functions['GUILD_MEMBER_UPDATE'] as $key => $func) $func($this, $member, $member_old);
            });
        } else $this->logger->debug('No GUILD_MEMBER_UPDATE functions found!');
        
        if (!empty($this->functions['GUILD_BAN_ADD'])) {
            $this->discord->on('GUILD_BAN_ADD', function (\Discord\Parts\Guild\Ban $ban)
            {
                 foreach ($this->functions['GUILD_BAN_ADD'] as $key => $func) $func($this, $ban);
            });
        } else $this->logger->debug('No GUILD_BAN_ADD functions found!');
        
        if (!empty($this->functions['GUILD_BAN_REMOVE'])) {
            $this->discord->on('GUILD_BAN_REMOVE', function (\Discord\Parts\Guild\Ban $ban)
            {
                 foreach ($this->functions['GUILD_BAN_REMOVE'] as $key => $func) $func($this, $ban);
            });
        } else $this->logger->debug('No GUILD_BAN_REMOVE functions found!');
        
        if (!empty($this->functions['userUpdate'])) {
            $this->discord->on('userUpdate', function (\Discord\Parts\User\User $user, ?\Discord\Parts\User\User $user_old)
            {
                 foreach ($this->functions['userUpdate'] as $key => $func) $func($this, $user, $user_old);
            });
        } else $this->logger->debug('No userUpdate functions found!');
    }
    
    public function reactionLoop($message, array $emojis) : void
    {
        $add = function ($message, $emojis) use (&$add) {
            if (count($emojis) != 0) $message->react(array_shift($emojis))->done(function () use ($add, $emojis, $message) {
                $add($message, $emojis);
            });
        };
        $add($message, $emojis);
    }
    
    public function roleReactionAdd(\Discord\Parts\WebSockets\MessageReaction $reaction)
    {
        //if (!is_null($emoji_id)) return; //Only unicode emojis are supported by Tutelar right now
        foreach ($this->discord_config[$reaction->guild_id]['reaction_roles'] as $key => $array)
        if ($reaction->message_id == $array['id']) foreach ($array['roles'] as $k => $v) {
            if (!isset($v['emoji'])) continue;
            if ($reaction->emoji == $v['emoji']) {
                if ($reaction->member->roles->get('name', $v['name']) ?? $reaction->member->roles->get('id', $v['id'])) return;
                if (! $role = ($reaction->guild->roles->get('name', $v['name']) ?? $reaction->guild->roles->get('id', $v['id']))) return $this->logger->warning('Unable to get configured role from server! ' . $v['name'] . ' : ' . $v['id']);
                $reaction->member->addRole($role->id);
                $reaction->channel->sendMessage($reaction->user . ' added the `' . $role->name . '` role!')->done(function ($message) {
                    $this->discord->getLoop()->addTimer(10, function () use ($message) { $message->delete(); });
                });
            }
        }
    }
    
    public function roleReactionRemove(\Discord\Parts\WebSockets\MessageReaction $reaction)
    {
        //if (!is_null($emoji_id)) return; //Only unicode emojis are supported by Tutelar right now
        foreach ($this->discord_config[$reaction->guild_id]['reaction_roles'] as $key => $array)
        if ($reaction->message_id == $array['id']) foreach ($array['roles'] as $k => $v) {
            if (!isset($v['emoji'])) continue;
            if ($reaction->emoji == $v['emoji']) {
                if (! $reaction->member->roles->get('name', $v['name']) ?? $reaction->member->roles->get('id', $v['id'])) return;
                if (! $role = $reaction->guild->roles->get('name', $v['name']) ?? $reaction->guild->roles->get('id', $v['id'])) return $this->logger->warning('Unable to get configured role from server! ' . $v['name'] . ' : ' . $v['id']);
                $reaction->member->removeRole($role->id);
                $reaction->channel->sendMessage($reaction->user . ' removed the `' . $role->name . '` role')->done(function ($message) {
                    $this->discord->getLoop()->addTimer(10, function () use ($message) { $message->delete(); });
                });
            }
        }
    }
    
    public function saveConfig() : bool
    {
        return $this->VarSave('discord_config.json', $this->discord_config);
    }
}