<?php
/**
 * @copyright 2012,2013 Binovo it Human Project, S.L.
 * @license http://www.opensource.org/licenses/bsd-license.php New-BSD
 */

namespace Binovo\ElkarTahoeBundle\Controller;

use \Exception;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Form\FormError;
use Binovo\ElkarBackupBundle\Entity\Message;

class DefaultController extends Controller
{

    /**
     * @Route("/tahoe/config", name="tahoeConfig")
     * @Template()
     */
    public function tahoeConfigAction(Request $request)
    {
        $context = array('source' => 'TahoeController::tahoeConfig');

        $t = $this->get('translator');
        $manager = $this->getDoctrine()->getManager();
        $tahoe = $this->container->get('Tahoe');

        if (!$tahoe->isInstalled()) { //it is supossed not to happend though
            return $this->redirect($this->generateUrl('manageBackupsLocation'));
        } 

        //obtain data from the node's config file
        $fields['nickname']='nickname';
        $fields['introducerfurl']='introducer.furl';
        $fields['sharesneeded']='shares.needed';
        $fields['shareshappy']='shares.happy';
        $fields['sharestotal']='shares.total';

        $data['nickname'] = 'elkarbackup_node';
        $data['introducerfurl'] = '';
        $data['sharesneeded'] = 3;  //K
        $data['shareshappy'] = 7;   //H
        $data['sharestotal'] = 10;  //N default values

        $nodeConfigFile = '/var/lib/elkarbackup/.tahoe/tahoe.cfg';
        if (file_exists($nodeConfigFile)) {
            try {
                $content = file_get_contents($nodeConfigFile);
                foreach ($fields as $key => $field) {
                    $data[$key]='';
                    $line = $field . ' = ';
                    $i=strpos($content, $line);                    
                    if ('#' == $content[$i-1]) {
                        break;                      
                    }
                    $i+=strlen($line);
                    while ("\n"!=$content[$i] and $i<strlen($content)) {
                        $data[$key].=$content[$i];
                        $i++;
                    }
                    $data[$key]=rtrim($data[$key]);
                }
            } catch (Exception $e) {
                $this->get('BnvWebLogger')->warn('Warning: the file could not be read. Default settings will be displayed', $context);
            }
        }

        $formBuilder = $this->createFormBuilder($data);
        $formBuilder->add('nickname'      , 'text'    , array('required' => false,
                                                              'label'    => $t->trans('Node nickname', array(), 'BinovoElkarTahoe'),
                                                              'attr'     => array('class'    => 'form-control')));
        $formBuilder->add('introducerfurl', 'textarea', array('required' => true,
                                                              'label'    => $t->trans('Introducer node furl', array(), 'BinovoElkarTahoe'),
                                                              'attr'     => array('class'   => 'form-control',
                                                                                  'style'   => 'resize: vertical; min-height: 74px; max-height: 114px;')));
        $formBuilder->add('sharesneeded'  , 'text'    , array('required' => false,
                                                              'label'    => 'K',
                                                              'attr'     => array('class'   => 'form-control',
                                                                                  'style'   => 'width: 52px;',
                                                                                  'maxlength' => 3)));
        $formBuilder->add('shareshappy'   , 'text' , array('required' => false,
                                                              'label'    => 'H',
                                                              'attr'     => array('class'   => 'form-control',
                                                                                  'style'   => 'width: 52px;',
                                                                                  'maxlength' => 3)));
        $formBuilder->add('sharestotal'   , 'text'    , array('required' => false,
                                                              'label'    => 'N',
                                                              'attr'     => array('class'   => 'form-control',
                                                                                  'style'   => 'width: 52px;',
                                                                                  'maxlength' => 3)));
        $form = $formBuilder->getForm();


        if ($request->isMethod('POST')) {

            foreach ($data as $key => $value) {
              $oldData[$key] = $value;
            }

            $form->bind($request);   
            $data = $form->getData();

            //0 = no change, 1 = change and ok, 2 = change but warning, 3 = change but error
            $changes[0] = 0;
            $data['nickname'] = str_replace(' ', '_', $data['nickname']);
            if ($oldData['nickname'] == $data['nickname']) {
                $changes[1] = 0;
            } else {
                if (''==$data['nickname']) {
                    $data['nickname']='elkarbackup_node';
                    $changes[1]=2;
                } else {
                    $changes[1]=1;
                }
            }
            if ($changes[1]>$changes[0]) {
                $changes[0]=$changes[1];
            }

            $data['introducerfurl'] = str_replace(' ', '', $data['introducerfurl']);
            if ($oldData['introducerfurl'] == $data['introducerfurl']) {
                $changes[2] = 0;
            } else {
                $expression = '#^pb:\/\/([a-z0-9]{32})@([a-z0-9\.]{1,})\.([a-z0-9\.]{1,}):([0-9]{5})([a-z0-9:,\.]*)\/([a-z0-9]{1,})#';
                if (preg_match($expression, $data['introducerfurl']) ) {
                    $changes[2] = 1;
                } else {
                    $data['introducerfurl'] = $oldData['introducerfurl'];
                    $changes[2] = 3;
                    $trans_msg = $t->trans('ERROR: Invalid introducer`s furl, try again', array(), 'BinovoElkarTahoe');
                    $form->addError(new FormError($trans_msg));
                }
            }
            if ($changes[2]>$changes[0]) {
                $changes[0]=$changes[2];
            }

            //KHN
            $data['sharesneeded'] = str_replace(' ', '', $data['sharesneeded']);
            $data['shareshappy'] = str_replace(' ', '', $data['shareshappy']);
            $data['sharestotal'] = str_replace(' ', '', $data['sharestotal']);
            if ($oldData['sharesneeded'] == $data['sharesneeded'] and
                $oldData['shareshappy'] == $data['shareshappy'] and
                $oldData['sharestotal'] == $data['sharestotal']) {
                $changes[3] = 0;
            } else {
                if (is_numeric($data['sharesneeded']) and is_numeric($data['sharestotal'])) { 
                    if (!is_numeric($data['shareshappy'])) {
                        if (0 < $data['sharesneeded'] and $data['sharesneeded'] <= $data['sharestotal']) {
                            $changes[3]=3;
                        } else {
                            $changes[3]=2;
                        }
                    } else {
                        if (0 < $data['sharesneeded'] and  $data['sharesneeded'] <= $data['sharestotal']) {
                            if ($data['sharesneeded'] <= $data['shareshappy'] and $data['shareshappy'] <= $data['sharestotal']) {
                                //everything ok ( 1 >= K >= H >= N )
                                $changes[3]=1;
                            } else {
                                $changes[3]=2;
                            }
                        } else {
                            $changes[3]=3;
                        }
                    }
                } else {
                    $changes[3] = 3;
                }
            }
            if (2==$changes[3]) { //catch problem
              //find a new H
              if ($data['sharesneeded']==$data['sharestotal']) {
                  $data['shareshappy'] = $data['sharesneeded'];
              } else {
                  $data['shareshappy'] = $data['sharestotal'] - $data['sharesneeded'];
                  if ($data['shareshappy'] < $data['sharesneeded']) {
                      $data['shareshappy'] = $data['shareshappy'] + 1;  //I trust servers' disponibility
                      //$data['shareshappy'] = $data['sharestotal'];    //I trust no-one (I should also use smaller K)
                  }
              }
            }
            if (3==$changes[3]) { //catch error
              $data['sharesneeded'] = $oldData['sharesneeded'];
              $data['shareshappy'] = $oldData['shareshappy'];
              $data['sharestotal'] = $oldData['sharestotal'];
              $trans_msg = $t->trans('Warning: wrong KHN parameters', array(), 'BinovoElkarTahoe');
              $form->addError(new FormError($trans_msg));
            }
            if ($changes[3]>$changes[0]) {
                $changes[0]=$changes[3];
            }


            if (3 > $changes[0]) {
                $msg = new Message('DefaultController', 'TickCommand',
                                     json_encode(array( 'command' => 'tahoe:config_node',
                                                        'i.furl' => $data['introducerfurl'],
                                                        's.K' => $data['sharesneeded'],
                                                        's.H' => $data['shareshappy'],
                                                        's.N' => $data['sharestotal'],
                                                        'nname' => $data['nickname']
                                                        )));
                $manager->persist($msg);
                $manager->flush();

                $seconds = 65-date("s"); //1 min + 5sec delay
                return $this->render('BinovoElkarTahoeBundle:Default:configuring.html.twig',
                                     array( 'form' => $form->createView(),
                                            'seconds' => $seconds));
            }

            foreach ($form->getErrors() as $error) {
                $errors[] = $error;
            }
            $formBuilder->setData($data);
            $form = $formBuilder->getForm();
            foreach ($errors as $error) {
                $form->addError($error);
            }
        }
        
        if (file_exists('/var/lib/elkarbackup/.tahoe/imReady.txt')) {
            $trans_msg = $t->trans('Tahoe storage is successfully configured', array(), 'BinovoElkarTahoe');
        } else {
            $trans_msg = $t->trans('ERROR: Tahoe storage is actually not configured properly or configured at all', array(), 'BinovoElkarTahoe');
        }
        $this->get('session')->getFlashBag()->add('tahoeConfiguration', $trans_msg);

        $nodeUrl = file_get_contents('/var/lib/elkarbackup/node.url');
        if (strlen($nodeUrl)<1) {
            $nodeUrl = 'http://127.0.0.1:3456/'; //default
        }

        return $this->render('BinovoElkarTahoeBundle:Default:configurenode.html.twig',
                                    array('form'        => $form->createView(),
                                          'gridStatus'  => $nodeUrl));
    }


    /**
     * @Route("/tahoe/backup/{action}/{file}", requirements={"action" = "view|download|downloadzip" , "file" = ".*"}, name="showJobTahoeBackup")
     * @Method("GET")
     */
    public function showJobTahoeBackupAction(Request $request, $action, $file)
    {
        $context = array('source' => 'TahoeController::showJobTahoeBackup');

        $tahoe = $this->container->get('Tahoe');
        $logger = $this->get('BnvWebLogger');
        $t = $this->get('translator');

        if (file_exists('/var/lib/elkarbackup/.tahoe/imReady.txt')) {
            $lenFile=strlen($file);
            $lenRoot=strlen('elkarbackup:Backups');
            if ($lenFile <= $lenRoot) {
                $father = 'elkarbackup:Backups';
            } else {
                $father = dirname($file);
            }

            if ('view' == $action) {
                $content = array();
                $command        = 'tahoe -d /var/lib/elkarbackup/ ls -l ' . $file . ' 2>&1';
                $commandOutput  = array();
                $status         = 0;
                exec($command, $commandOutput, $status);
                if (0 == $status) {
                    $dirCount = count($commandOutput);

                    if ($dirCount>0) {
                        $isDir=array();

                        $retainsLevel = false;
                        for ($i=0; $i<$dirCount; $i++) {
                            //format: drwx <size> <date/time> <name in this directory>
                            //ex: dr-x - Nov 16 09:52 testbackup
                            if ('d' == $commandOutput[$i][0]) {
                                $isDir = true;
                            } else {
                                $isDir = false;
                            }
                            $j=5;
                            $size='';
                            while (' '!=$commandOutput[$i][$j]) {
                                $size.=$commandOutput[$i][$j];
                                $j++;
                            }
                            if ('-'!=$size) { //convert from bytes to units
                                $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
                                if ($size>0) {
                                    $power = floor(log(intval($size), 1024));
                                } else {
                                    $power = 0;
                                }
                                $size = number_format(intval($size) / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
                            }
                            $j++;
                            $dateTime = '';
                            while (':'!=$commandOutput[$i][$j]) {
                                $dateTime.=$commandOutput[$i][$j];
                                $j++;
                            }
                            $j+=4;
                            $name = '';
                            while (' '==$commandOutput[$i][$j] and $j<strlen($commandOutput[$i])) {
                                $j++;
                            }
                            while ($j < strlen($commandOutput[$i])) {
                                $name.=$commandOutput[$i][$j];
                                $j++;
                            }
                            if ('Archives' == $name) {
                                $retainsLevel = true;
                            }
                            $content[$i] = array($name, $size, $isDir);
                        }

                        if ($retainsLevel) { //sort
                            $aux = array();
                            foreach ($content as $value) {
                                switch ($value[0]) {
                                    case 'Latest':
                                        $latest = $value;
                                        break;
                                    case 'Hourly':
                                        $hourly = $value;
                                        break;
                                    case 'Archives':
                                        break;
                                    default:
                                        $aux[] = $value;
                                        break;
                                }
                            }
                            $content = array();
                            $content[] = $latest;
                            $content[] = $hourly;
                            foreach ($aux as $value) {
                                $content[] = $value;
                            }
                        }
                    }
                }

                system('which zip', $cmdretval);
                $isZipInstalled = !$cmdretval;

                $logger->info('Browse Tahoe repository ',
                            array('link' => $this->generateUrl('showJobTahoeBackup', array('action'   => $action,
                                                                                           'file'     => $file))));
                $this->getDoctrine()->getManager()->flush();

                return $this->render('BinovoElkarTahoeBundle:Default:tahoeDirectory.html.twig',
                                         array('content'        => $content,
                                               'filePath'       => $file,
                                               'fatherDir'      => $father,
                                               'isZipInstalled' => $isZipInstalled));
            } else {
                if (0!=strcmp('elkarbackup:Backups', $file)) {
                    $filename = '';
                    $i = strlen($father)+1;
                    for (; $i<strlen($file); $i++) {
                        $filename.=$file[$i];
                    }
                } else {
                    $filename = 'Backups';
                }
                $filename = str_replace(' ', '', $filename);
                $realPath = '/tmp/elkarbackup/' . $filename;

                if (false!=strpos($file, ' ')) {
                    $dirName = dirname($file);
                    $fileFixed = basename($file);
                    $fileFixed = "'" . $fileFixed . "'";
                    while (0!=strcmp('.', $dirName)) {
                        $baseName = basename($dirName);
                        $dirName = dirname($dirName);
                        $baseName = "'" . $baseName . "'";
                        $fileFixed = $baseName . '/' . $fileFixed;
                    }
                } else {
                    $fileFixed = $file;
                }

                $command        = 'tahoe -d /var/lib/elkarbackup/ cp -r ' . $fileFixed . ' ' . $realPath . ' 2>&1';
                $commandOutput  = array();
                $status         = 0;
                exec($command, $commandOutput, $status);
                if (0 != $status) {
                    $logger->err('Error: Tahoe cannot retrieve that directory from the grid - ' . $file, $context);
                    return $this->redirect($this->generateUrl('showJobTahoeBackup', array('action' => 'view', 'file' => $father)));
                }

                if ('download' == $action) {
                    $headers = array('Content-Type' => 'application/x-gzip',
                                     'Content-Disposition' => sprintf('attachment; filename="%s.tar.gz"', basename($realPath)));

                    $f = function() use ($realPath) {
                        $command = sprintf('cd "%s"; tar zc "%s"; rm -r "%s"', dirname($realPath), basename($realPath), dirname($realPath));
                        passthru($command);
                    };
                } else { // ('downloadzip' == $action)
                    $headers = array('Content-Type'        => 'application/zip',
                                     'Content-Disposition' => sprintf('attachment; filename="%s.zip"', basename($realPath)));
                    $f = function() use ($realPath) {
                        $command = sprintf('cd "%s"; zip -r - "%s"; rm -r "%s"', dirname($realPath), basename($realPath), dirname($realPath));
                        passthru($command);
                    };
                }
                $logger->info('Download backup directory ',
                              array('link' => $this->generateUrl('showJobTahoeBackup', array('action' => $action, 'file' => $file))));
                $this->getDoctrine()->getManager()->flush();

                return new StreamedResponse($f, 200, $headers);
            }

        } else {
            $logger->err('Error: Tahoe is not configured', $context);
        }
        return $this->redirect($this->generateUrl('tahoeConfig'));
    }


}
