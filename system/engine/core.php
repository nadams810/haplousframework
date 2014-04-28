<?php

include "system/engine/controller.php";
include "system/engine/SMTP.php";
include "system/engine/exceptions.php";

class HF_Core
{
	private $class;
	private $method;
	private $classname;
	private $args = array();
    private $config = array();
    private $tpl;
	
	public function __construct()
	{
        $config = include("system/engine/config-default.php");
        if (is_file("application/config.php"))
        {
            $newconfig = include("application/config.php");
        }
        $this->config = array_merge($config, $newconfig);
        if ($this->config["USE_H20_TPL"])
            $this->tpl = new H2o(null, array(
                "searchpath" => getcwd() . "/application/views/",
                "cache_dir" => "application/tmp/",
                'cache' => 'file'
            ));
        set_error_handler("HF_Core::error_handler");
        $this->findController();
	}

    public function siteURL()
    {
        if (isvarset($this->config["SITE_URL"]))
        {
            return $this->config["SITE_URL"];
        }
        $path = explode("/", $_SERVER["REQUEST_URI"]);
        $path = array_filter($path, 'strlen');
        if (count($path) == 0)
        {
            return  $_SERVER["HTTP_HOST"] . "/";
        } else {
            if (in_array($this->classname, $path))
            {
                $newpath = implode("/", array_splice($path, 0, -2));
                return $_SERVER["HTTP_HOST"] . "/" . $newpath . "/";
            } else {
                $newpath = implode("/", $path);
                return $_SERVER["HTTP_HOST"] . "/" . $newpath . "/";
            }
        }
    }
	
	private function findController()
	{
        try
        {
            if (isvarset($_SERVER["PATH_INFO"]))
            {
                $request = $_SERVER["PATH_INFO"];
                //$request = $_SERVER["PHP_SELF"];
                $splitreq = explode("/", $request);
                /*$request = "";
                for($i = 0; $i < count($splitreq); $i++)
                {
                    if ($splitreq[$i] == "index.php")
                    {
                        $request = implode("/", array_splice($splitreq, $i+1));
                    }
                }*/
                //print $request;
                //$request = substr($request, 1);
                //$request = substr($request, 0, -1);
            } else {
                $request = "";
            }
            if ($request == "" || $request == "/")
            {
                require("application/controllers/" . $this->config["DEFAULT_ROUTE"] . ".php");
                $this->loadController(new $this->config["DEFAULT_ROUTE"]($this->config, $this, $this->tpl), $this->config["DEFAULT_ROUTE"], "index");
                return;
            }
            if ($request[strlen($request)-1] == "/")
                $request = substr($request, 0, -1);
            $arr = explode("/", $request);
            $path = "application/controllers/";
            for($i = 0; $i < count($arr); $i++)
            {
                if (is_file($path . $arr[$i] . ".php")) // found the controller
                {
                    include($path . $arr[$i] . ".php");
                    if ($i + 1 < count($arr)) // if there is a define after the controller name - this would be the method name
                    {
                        $this->loadController(new $arr[$i]($this->config, $this, $this->tpl), $arr[$i], $arr[$i+1], array_slice ($arr, 2));
                    } else { // call index
                        $this->loadController(new $arr[$i]($this->config, $this, $this->tpl), $arr[$i], "index");
                    }
                    return;
                }

                if (is_dir($path . $arr[$i])) // controller is hidden deeper
                {
                    $path = $path . $arr[$i] . "/";
                    continue;
                }

                include($path . $this->config["DEFAULT_ROUTE"] . ".php");
                $this->loadController(new $this->config["DEFAULT_ROUTE"]($this->config, $this, $this->tpl), $this->config["DEFAULT_ROUTE"], "index");
                //$this->load404Controller();
                break;
                // throw exception controller not found
            }
        } catch (Exception $e) {
            if ($this->config["DEBUG"])
                echo vdump($e, $this);
            else
                $this->mail_admins("[Exception - " . $this->config["SITE_NAME"] . "]", vdump($e, $this), true);
        }
	}

    private function load404Controller()
    {
        if (is_file(getcwd() . "/application/status.php"))
        {
            include_once (getcwd() . "/application/status.php");
            $this->loadController(new status($this->config, $this, $this->tpl), "status", "Status404");
        } else {
            include_once(getcwd() . "/system/engine/status.php");
            $this->loadController(new HF_Status($this->config, $this, $this->tpl), "HF_Status", "Status404");

        }
    }

    private function load500Controller()
    {
        if (is_file(getcwd() . "/application/status.php"))
        {
            include_once (getcwd() . "/application/status.php");
            $this->loadController(new status($this->config, $this, $this->tpl), "status", "Status500");
        } else {
            include_once (getcwd() . "/system/engine/status.php");
            $this->loadController(new HF_Status($this->config, $this, $this->tpl), "HF_Status", "Status500");

        }
    }

    private function loadController($class, $classname, $method, $args = array())
    {
        $this->class = $class;
        $this->classname = $classname;
        $this->method = $method;
        $this->args = $args;
    }
	
	public function run($err=false)
	{
        try
        {
            $call = new ReflectionMethod($this->classname, $this->method);
            if ($err)
            {
                $call->invokeArgs($this->class, $this->args);
                return;
            }

            $numOfReqPara = $call->getNumberOfRequiredParameters();
            $numOfOptPara = $call->getNumberOfParameters() - $numOfReqPara;
            $remainparas = count($this->args) - $numOfReqPara;
            if ($remainparas >= 0 && $remainparas <= $numOfOptPara)
            {
                $call->invokeArgs($this->class, $this->args);
            }
            else
            {
                $this->load404Controller();
                $this->run(true);
            }

        } catch (ReflectionException $e)
        {
            if (strstr($e->getMessage(), "does not exist") !== false)
            {
                $this->load404Controller();
            } else {
                $this->load500Controller();
            }
            $this->run(true);
            if ($this->config["DEBUG"])
                echo vdump($e, $this);
            else
                $this->mail_admins("[Exception - " . $this->config["SITE_NAME"] . "]", vdump($e, $this), true);


        } catch (Exception $e) {
            $this->load500Controller();
            $this->run(true);
            if ($this->config["DEBUG"])
                echo vdump($e, $this);
            else
                $this->mail_admins("[Exception - " . $this->config["SITE_NAME"] . "]", vdump($e, $this), true);
        }
	}

    public function mail_admins($subject, $msg, $html = false)
    {
        if (array_key_exists("ADMINS", $this->config))
        {
            foreach($this->config["ADMINS"] as $email)
            {
                $this->mail_user($email, $subject, $msg, $html);
            }
        }
    }

    public function mail_user($to, $subject, $msg, $html = false)
    {
        if ($this->config["USE_HF_SMTP"])
        {
            $smtp = new HF_SMTP($this->config["SMTP_FROM"], $to, $subject, $msg, $this->config["SMTP_SERVER"], $this->config["SMTP_USER"], $this->config["SMTP_PASS"], $this->config["SMTP_PORT"]);
            $smtp->send($html);
        } else {
            require_once "Mail.php";
            $smtp = null;
            if ($this->$this->config["SMTP_USER"] && $this->config["SMTP_PASS"])
                $smtp = Mail::factory('smtp', array(
                    "host" => $this->config["SMTP_SERVER"],
                    "port" => $this->config["SMTP_PORT"],
                    "auth" => true,
                    'username' => $this->config["SMTP_USER"],
                    'password' => $this->config["SMTP_PASS"]
                ));
            else
                $smtp = Mail::factory('smtp', array(
                    "host" => $this->config["SMTP_SERVER"],
                    "port" => $this->config["SMTP_PORT"]
                ));
            $headers = array ('From' => $this->config["SMTP_FROM"],
                'To' => $to,
                'Subject' => $subject);
            $smtp->send($to, $headers, $msg);
        }
    }

    public static function error_handler($err_severity, $err_msg, $err_file, $err_line, array $err_context)
    {
        if (0 === error_reporting()) { return false;}
        switch($err_severity)
        {
            case E_ERROR:               throw new ErrorException            ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_WARNING:             throw new WarningException          ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_PARSE:               throw new ParseException            ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_NOTICE:              throw new NoticeException           ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_CORE_ERROR:          throw new CoreErrorException        ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_CORE_WARNING:        throw new CoreWarningException      ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_COMPILE_ERROR:       throw new CompileErrorException     ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_COMPILE_WARNING:     throw new CoreWarningException      ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_USER_ERROR:          throw new UserErrorException        ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_USER_WARNING:        throw new UserWarningException      ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_USER_NOTICE:         throw new UserNoticeException       ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_STRICT:              throw new StrictException           ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_RECOVERABLE_ERROR:   throw new RecoverableErrorException ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_DEPRECATED:          throw new DeprecatedException       ($err_msg, 0, $err_severity, $err_file, $err_line);
            case E_USER_DEPRECATED:     throw new UserDeprecatedException   ($err_msg, 0, $err_severity, $err_file, $err_line);
        }

    }
}