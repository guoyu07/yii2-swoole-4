<?php

namespace swooleunit\controllers;

use Yii;
use yii\base\Exception;
use yii\filters\AccessControl;
use yii\log\access\AccessLog;
use yii\log\Logger;
use yii\web\Controller;
use yii\filters\VerbFilter;
use app\models\LoginForm;
use app\models\ContactForm;
use yii\web\Response;

class SiteController extends Controller
{
    /**
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex()
    {
//        if(!Yii::$app->session->has('a')){
//            Yii::$app->session['a'] =  rand();
//        }
        return $this->render('index');
    }

    public function actionJson(){
        Yii::$app->response->format = Response::FORMAT_JSON;
        return ['time' => time(), 'str' => 'hello'];
    }

    public function actionLog(){
        $al = new AccessLog();
        Yii::$app->set('accessLog',$al);
        Yii::getLogger()->log($al,Logger::LEVEL_ACCESS);
    }

    public function actionException(){
        throw new Exception('test error');
    }

    public function actionError()
    {
        return $a;      
    }
}
