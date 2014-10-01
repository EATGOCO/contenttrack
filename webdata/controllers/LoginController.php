<?php

class LoginController extends Pix_Controller
{
    protected function getGoogleConsumer()
    {
        set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . '/../stdlibs/php-openid');
        include(__DIR__ . '/../stdlibs/php-openid/Auth/OpenID/Consumer.php');
        include(__DIR__ . '/../stdlibs/php-openid/Auth/OpenID/AX.php');
        include(__DIR__ . '/../stdlibs/php-openid/Auth/OpenID/PAPE.php');
        include(__DIR__ . '/../stdlibs/php-openid/Auth/OpenID/Interface.php');

        $store = new AuthOpenIDSessionStore();
        $consumer = new Auth_OpenID_Consumer($store);
        return $consumer;
    }

    public function googledoneAction()
    {
      $email = $_POST['email'] . '@eatgo.com';

        if (!$user = User::search(array('user_name' => 'google://' . $email))->first()) {
          list($name, $domain) = explode('@', $email);
          if ('eatgo.com' != $domain) {
            return $this->alert('您不在管理名單中', '/');
          }
          $user = User::insert(array('user_name' => 'google://' . $email));
        }
        Pix_Session::set('user_id', $user->user_id);
        return $this->redirect('/');
    }

    public function googleAction()
    {
    }

    public function logoutAction()
    {
        Pix_Session::delete('user_id');
        return $this->redirect('/');
    }
}
