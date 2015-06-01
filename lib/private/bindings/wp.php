<?php
    include_once_exists(dirname(__FILE__).'/../../config/config.wp.php');

    array_push(PluginRegistry::$Classes, 'WPBinding');

    class WPBinding extends Binding
    {
        private static $BindingName = 'wp';

        public static $HashMethod_md5  = 'wp_md5';
        public static $HashMethod_md5r = 'wp_md5r';

        // -------------------------------------------------------------------------

        public function getName()
        {
            return self::$BindingName;
        }

        // -------------------------------------------------------------------------

        public function getConfig()
        {
            $Config = new BindingConfig();

            $Config->Database         = defined('WP_DATABASE') ? WP_DATABASE : RP_DATABASE;
            $Config->User             = defined('WP_USER') ? WP_USER : RP_USER;
            $Config->Password         = defined('WP_PASS') ? WP_PASS : RP_PASS;
            $Config->Prefix           = defined('WP_TABLE_PREFIX') ? WP_TABLE_PREFIX : 'wp_';
            $Config->Version          = defined('WP_VERSION') ? WP_VERSION : 30000;
            $Config->AutoLoginEnabled = defined('WP_AUTOLOGIN') ? WP_AUTOLOGIN : false;
            $Config->CookieData       = defined('WP_SECRET') ? WP_SECRET : '';
            $Config->Members          = defined('WP_MEMBER_GROUPS') ? explode(',', WP_MEMBER_GROUPS ) : array();
            $Config->Privileged       = defined('WP_PRIVILEGED_GROUPS') ? explode(',', WP_PRIVILEGED_GROUPS ) : array();
            $Config->Raidleads        = defined('WP_RAIDLEAD_GROUPS') ? explode(',', WP_RAIDLEAD_GROUPS ) : array();
            $Config->Admins           = defined('WP_ADMIN_GROUPS') ? explode(',', WP_ADMIN_GROUPS ) : array();
            $Config->HasCookieConfig  = true;
            $Config->HasGroupConfig   = true;

            return $Config;
        }

        // -------------------------------------------------------------------------

        public function getExternalConfig($aRelativePath)
        {
            $Out = Out::getInstance();
            $ConfigPath = $_SERVER['DOCUMENT_ROOT'].'/'.$aRelativePath.'/wp-config.php';
            if (!file_exists($ConfigPath))
            {
                $Out->pushError($ConfigPath.' '.L('NotExisting').'.');
                return null;
            }

            define('SHORTINIT', true);
            @include_once($ConfigPath);

            if (!isset($table_prefix))
            {
                $Out->pushError(L('NoValidConfig'));
                return null;
            }

            $VersionElements = explode('.', $wp_version);
            $Version = $VersionElements[0] * 10000 +
                ((isset($VersionElements[1])) ? $VersionElements[1] * 100 : 0) +
                ((isset($VersionElements[2])) ? $VersionElements[2] : 0);

            return array(
                'database'  => DB_NAME,
                'user'      => DB_USER,
                'password'  => DB_PASSWORD,
                'prefix'    => $table_prefix,
                'cookie'    => LOGGED_IN_KEY.LOGGED_IN_SALT,
                'version'   => $Version
            );
        }

        // -------------------------------------------------------------------------

        public function writeConfig($aEnable, $aConfig)
        {
            $Config = fopen( dirname(__FILE__).'/../../config/config.wp.php', 'w+' );

            fwrite( $Config, "<?php\n");
            fwrite( $Config, "\tdefine('WP_BINDING', ".(($aEnable) ? "true" : "false").");\n");

            if ( $aEnable )
            {
                fwrite( $Config, "\tdefine('WP_DATABASE', '".$aConfig->Database."');\n");
                fwrite( $Config, "\tdefine('WP_USER', '".$aConfig->User."');\n");
                fwrite( $Config, "\tdefine('WP_PASS', '".$aConfig->Password."');\n");
                fwrite( $Config, "\tdefine('WP_TABLE_PREFIX', '".$aConfig->Prefix."');\n");
                fwrite( $Config, "\tdefine('WP_SECRET', '".$aConfig->CookieData."');\n");
                fwrite( $Config, "\tdefine('WP_AUTOLOGIN', ".(($aConfig->AutoLoginEnabled) ? "true" : "false").");\n");

                fwrite( $Config, "\tdefine('WP_MEMBER_GROUPS', '".implode( ",", $aConfig->Members )."');\n");
                fwrite( $Config, "\tdefine('WP_PRIVILEGED_GROUPS', '".implode( ",", $aConfig->Privileged )."');\n");
                fwrite( $Config, "\tdefine('WP_RAIDLEAD_GROUPS', '".implode( ",", $aConfig->Raidleads )."');\n");
                fwrite( $Config, "\tdefine('WP_ADMIN_GROUPS', '".implode( ",", $aConfig->Admins )."');\n");
                fwrite( $Config, "\tdefine('WP_VERSION', ".$aConfig->Version.");\n");
            }

            fwrite( $Config, '?>');

            fclose( $Config );
        }

        // -------------------------------------------------------------------------

        public function getGroups($aDatabase, $aPrefix, $aUser, $aPass, $aThrow)
        {
            $Connector = new Connector(SQL_HOST, $aDatabase, $aUser, $aPass, $aThrow);

            if ($Connector != null)
            {
                $OptionsQuery = $Connector->prepare( 'SELECT option_value FROM `'.$aPrefix.'options` WHERE option_name = "'.$aPrefix.'user_roles" LIMIT 1' );
                $Option = $OptionsQuery->fetchFirst($aThrow);

                $Groups = array();
                $Roles = unserialize($Option['option_value']);

                if (is_array($Roles))
                {
                    foreach ($Roles as $Role => $Options)
                    {
                        array_push( $Groups, array(
                            'id'   => strtolower($Role),
                            'name' => $Role)
                        );
                    }
                }

                return $Groups;
            }

            return null;
        }

        // -------------------------------------------------------------------------

        public function getForums($aDatabase, $aPrefix, $aUser, $aPass, $aThrow)
        {
            return null;
        }

        // -------------------------------------------------------------------------

        public function getUsers($aDatabase, $aPrefix, $aUser, $aPass, $aThrow)
        {
            return null;
        }

        // -------------------------------------------------------------------------

        private function getGroup( $aUserId )
        {
            $Connector = $this->getConnector();
            $Config = $this->getConfig();
            $AssignedGroup = ENUM_GROUP_NONE;

            $MetaQuery = $Connector->prepare('SELECT meta_key, meta_value '.
                'FROM `'.WP_TABLE_PREFIX.'usermeta` '.
                'WHERE user_id = :UserId AND meta_key = "'.WP_TABLE_PREFIX.'capabilities" LIMIT 1');

            $MetaQuery->bindValue(':UserId', $aUserId, PDO::PARAM_INT);

            $MetaQuery->loop(function($MetaData) use (&$AssigedGroup, $Config)
            {
                $Roles = array_keys(unserialize($MetaData['meta_value']));
                foreach($Roles as $Role)
                {
                    $AssignedGroup = $Config->mapGroup($Role, $AssignedGroup);
                }
            });

            return GetGroupName($AssignedGroup);
        }

        // -------------------------------------------------------------------------

        private function generateInfo( $aUserData )
        {
            $Info = new UserInfo();
            $Info->UserId      = $aUserData['ID'];
            $Info->UserName    = $aUserData['user_login'];
            $Info->Password    = $aUserData['user_pass'];
            $Info->Salt        = self::extractSaltPart($aUserData['user_pass']);
            $Info->SessionSalt = null;
            $Info->Group       = $this->getGroup($aUserData['ID']);
            $Info->BindingName = $this->getName();
            $Info->PassBinding = $this->getName();

            return $Info;
        }

        // -------------------------------------------------------------------------

        public function getExternalLoginData()
        {
            if (!defined('WP_AUTOLOGIN') || !WP_AUTOLOGIN)
                return null;

            $UserInfo = null;

            if (defined('WP_SECRET'))
            {
                $Connector = $this->getConnector();

                // Fetch cookie name

                $ConfigQuery = $Connector->prepare('SELECT option_value '.
                    'FROM `'.WP_TABLE_PREFIX.'options` '.
                    'WHERE option_name = "siteurl" LIMIT 1');

                $ConfigData = $ConfigQuery->fetchFirst();

                if ( $ConfigData != null )
                {
                    $CookieName = 'wordpress_logged_in_'.md5($ConfigData['option_value']);

                    // Fetch user info if seesion cookie is set

                    if (isset($_COOKIE[$CookieName]))
                    {
                        if (!defined("WP_VERSION") || WP_VERSION < 40000)
                        {
                            list($UserName, $Expiration, $hmac) = explode('|', $_COOKIE[$CookieName]);

                            $UserInfo = $this->getUserInfoByName($UserName);

                            if ($UserInfo != null)
                            {
                                $PassFragment = substr($UserInfo->Password, 8, 4);

                                $Key3x  = hash_hmac('md5', $UserName.$PassFragment.'|'.$Expiration, WP_SECRET);
                                $Hash3x = hash_hmac('md5', $UserName . '|' . $Expiration, $Key3x);

                                if (($Hash3x != $hmac) ||
                                    ($Expiration < time()))
                                {
                                    $UserInfo = null;
                                }
                            }
                        }
                        else
                        {
                            list($UserName, $Expiration, $Token, $hmac) = explode('|', $_COOKIE[$CookieName]);

                            $UserInfo = $this->getUserInfoByName($UserName);

                            if ($UserInfo != null)
                            {
                                $PassFragment = substr($UserInfo->Password, 8, 4);

                                $Key4x  = hash_hmac('md5', $UserName.'|'.$PassFragment.'|'.$Expiration.'|'.$Token, WP_SECRET);
                                $Hash4x = hash_hmac('sha256', $UserName.'|'.$Expiration.'|'.$Token, $Key4x);

                                if (($Hash4x != $hmac) ||
                                    ($Expiration < time()))
                                {
                                    $UserInfo = null;
                                }
                            }
                        }

                    }
                }
            }

            return $UserInfo;
        }

        // -------------------------------------------------------------------------

        public function getUserInfoByName( $aUserName )
        {
            $Connector = $this->getConnector();
            $UserQuery = $Connector->prepare('SELECT ID, user_login, user_pass, user_status '.
                'FROM `'.WP_TABLE_PREFIX.'users` '.
                'WHERE LOWER(user_login) = :Login LIMIT 1');

            $UserQuery->BindValue( ':Login', strtolower($aUserName), PDO::PARAM_STR );
            $UserData = $UserQuery->fetchFirst();

            return ($UserData != null)
                ? $this->generateInfo($UserData)
                : null;
        }

        // -------------------------------------------------------------------------

        public function getUserInfoById( $aUserId )
        {
            $Connector = $this->getConnector();
            $UserQuery = $Connector->prepare('SELECT ID, user_login, user_pass, user_status '.
                'FROM `'.WP_TABLE_PREFIX.'users` '.
                'WHERE ID = :UserId LIMIT 1');

            $UserQuery->BindValue( ':UserId', $aUserId, PDO::PARAM_INT );
            $UserData = $UserQuery->fetchFirst();

            return ($UserData != null)
                ? $this->generateInfo($UserData)
                : null;
        }

        // -------------------------------------------------------------------------

        private static function extractSaltPart( $aPassword )
        {
            global $gItoa64;

            if ((strlen($aPassword) == 34) || (substr($aPassword, 0, 3) == '$P$'))
            {
                $Count = strpos($gItoa64, $aPassword[3]);
                $Salt = substr($aPassword, 4, 8);

                return $Count.':'.$Salt;
            }

            return '';
        }

        // -------------------------------------------------------------------------

        public function getMethodFromPass( $aPassword )
        {
            return ((strlen($aPassword) == 34) || (substr($aPassword, 0, 3) == '$P$'))
                ? self::$HashMethod_md5r
                : self::$HashMethod_md5;
        }

        // -------------------------------------------------------------------------

        public function hash( $aPassword, $aSalt, $aMethod )
        {
            global $gItoa64;

            if ($aMethod == self::$HashMethod_md5 )
            {
                return md5($aPassword);
            }

            $Parts   = explode(':',$aSalt);
            $CountB2 = intval($Parts[0],10);
            $Count   = 1 << $CountB2;
            $Salt    = $Parts[1];

            $Hash = md5($Salt.$aPassword, true);

            do {
                $Hash = md5($Hash.$aPassword, true);
            } while (--$Count);

            return '$P$'.$gItoa64[$CountB2].$Salt.encode64($Hash,16);
        }

        // -------------------------------------------------------------------------

        public function post($aSubject, $aMessage)
        {

        }
    }
?>
