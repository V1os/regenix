<?php
namespace controllers;

use framework\cache\Cache;
use framework\libs\I18n;
use models\Group;
use models\User;
use notifiers\ConfirmNotifier;

class Application extends AppController {

    public function page($page = 'index'){

        $user = User::find(User::query()->eq('email', 'dz@dim-s.net'))->first();
        dump($user);

        if (!$user)
            $user = new User();

        $t = microtime(1);
        for($i = 0; $i < 3500; $i++){
            $user->login = 'dim-s';
            $user->groups = array();
            $user->email = 'dz@dim-s.net';
            $user->password = '123456';
            $user->register();
        }
        dump(microtime(1) - $t);

        $lang     = I18n::getLang();
        if ($lang === 'default' || $lang === 'en')
            $lang = '';

        $template = $this->actionMethodReflection->getDeclaringClass()->getShortName()
            . '/' . $lang . '/' . $page . '.html';
        if (!$this->templateExists($template))
            $template = $this->actionMethodReflection->getDeclaringClass()->getShortName() . '/' . $page . '.html';

        if (!$this->templateExists($template))
            $this->notFound($page);

        $this->put('page', $template);
        $this->render('.page.html');
    }
}