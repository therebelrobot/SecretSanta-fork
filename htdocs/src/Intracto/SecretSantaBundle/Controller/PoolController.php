<?php

namespace Intracto\SecretSantaBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use JMS\DiExtraBundle\Annotation as DI;
use Intracto\SecretSantaBundle\Entity\Pool;
use Intracto\SecretSantaBundle\Form\PoolType;

class PoolController extends Controller
{
    /**
     * @DI\Inject("%admin_email%")
     */
    public $adminEmail;

    /**
     * @Route("/", name="pool_create")
     * @Template()
     */
    public function createAction(Request $request)
    {
        $pool = new Pool();

        $form = $this->createForm(new PoolType(), $pool);

        if ('POST' === $request->getMethod()) {
            $form->bind($request);
            if ($form->isValid()) {
                foreach ($pool->getEntries() as $entry) {
                    $entry->setPool($pool);
                }

                $em = $this->getDoctrine()->getManager();
                $em->persist($pool);
                $em->flush();

                // Send pending confirmation mail
                $message = \Swift_Message::newInstance()
                    ->setSubject('Secret Santa Confirmation')
                    ->setFrom($this->adminEmail, "Santa Claus")
                    ->setTo($pool->getOwnerEmail())
                    ->setBody(
                        $this->renderView(
                            'IntractoSecretSantaBundle:Emails:pendingconfirmation.txt.twig',
                            array('pool' => $pool)
                        )
                    )
                    ->addPart(
                        $this->renderView(
                            'IntractoSecretSantaBundle:Emails:pendingconfirmation.html.twig',
                            array('pool' => $pool)
                        ),
                        'text/html'
                    )
                ;
                $this->get('mailer')->send($message);

                return new Response(
                    $this->renderView('IntractoSecretSantaBundle:Pool:created.html.twig', array('pool' => $pool))
                );
            }
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/manage/{listUrl}", name="pool_manage")
     * @Template()
     */
    public function manageAction($listUrl)
    {
        $this->getPool($listUrl);

        if ($this->pool->getSentdate() === null) {
            $first_time = true;
          /** @var \Intracto\SecretSantaBundle\Entity\EntryService $entryService */
            $entryService = $this->get('intracto_secret_santa.entry_service');

            $entryService->shuffleEntries($this->pool);
            $entryService->sendSecretSantaMailsForPool($this->pool);
        } else {
            $first_time = false;
        }

        return array('pool' => $this->pool, 'first_time' => $first_time);
    }

    /**
     * @Route("/resend/{listUrl}/{entryId}", name="pool_resend")
     * @Template("IntractoSecretSantaBundle:Pool:manage.html.twig")
     */
    public function resendAction($listUrl, $entryId)
    {
        $entry = $this->getDoctrine()->getRepository('IntractoSecretSantaBundle:Entry')->find($entryId);

        if (!is_object($entry)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        if ($entry->getPool()->getListUrl() !== $listUrl) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        $entryService = $this->get('intracto_secret_santa.entry_service');
        $entryService->sendSecretSantaMailForEntry($entry);

        $this->get('session')->getFlashBag()->add('success', '<strong>Resent!</strong><br/>The e-mail to ' . $entry->getName() . ' has been resent.<br/>');

        return $this->redirect($this->generateUrl('pool_manage',array('listUrl' => $listUrl)));
    }

    /**
     * Retrieve pool by url
     *
     * @param string $url
     *
     * @return boolean
     */
    protected function getPool($listurl)
    {
        $this->pool = $this->getDoctrine()->getRepository('IntractoSecretSantaBundle:Pool')->findOneByListurl($listurl);

        if (!is_object($this->pool)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        return true;
    }
}
