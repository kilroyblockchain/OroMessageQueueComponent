<?php
namespace Oro\Component\MessageQueue\Job;

use Oro\Component\MessageQueue\Client\MessageProducer;

class JobProcessor
{
    /**
     * @var JobStorage
     */
    private $jobStorage;

    /**
     * @var MessageProducer
     */
    private $producer;

    /**
     * @param JobStorage      $jobStorage
     * @param MessageProducer $producer
     */
    public function __construct(JobStorage $jobStorage, MessageProducer $producer)
    {
        $this->jobStorage = $jobStorage;
        $this->producer = $producer;
    }

    /**
     * @param string $id
     *
     * @return Job
     */
    public function findJobById($id)
    {
        return $this->jobStorage->findJobById($id);
    }
    
    /**
     * @param string $name
     * @param Job    $root
     * @param bool   $unique
     *
     * @return Job
     *
     * @throws DuplicateJobException
     */
    public function createJob($name, Job $root = null, $unique = false)
    {
        if ($root && ! $root->isRoot()) {
            throw new \LogicException(sprintf(
                'You can append jobs only to root job but it is not. id: "%s"',
                $root->getId()
            ));
        }

        if ($root && $unique) {
            throw new \LogicException('Can create only root unique jobs.');
        }

        $job = new Job();
        $job->setStatus(Job::STATUS_NEW);
        $job->setName($name);
        $job->setCreatedAt(new \DateTime());
        $job->setRootJob($root);
        $job->setUnique((bool) $unique);

        try {
            $this->jobStorage->saveJob($job);

            if ($root) {
                $this->producer->send(Topics::CALCULATE_ROOT_JOB_STATUS, [
                    'id' => $job->getId()
                ]);
            }

            return $job;
        } catch (DuplicateJobException $e) {
            if ($unique) {
                return;
            }

            throw $e;
        }
    }

    /**
     * @param Job $job
     */
    public function startChildJob(Job $job)
    {
        if ($job->isRoot()) {
            throw new \LogicException(sprintf('Can\'t start root jobs. id: "%s"', $job->getId()));
        }

        if ($job->getStatus() !== Job::STATUS_NEW) {
            throw new \LogicException(sprintf(
                'Can start only new jobs: id: "%s", status: "%s"',
                $job->getId(),
                $job->getStatus()
            ));
        }

        $job->setStatus(Job::STATUS_RUNNING);
        $job->setStartedAt(new \DateTime());

        $this->jobStorage->saveJob($job);

        $this->producer->send(Topics::CALCULATE_ROOT_JOB_STATUS, [
            'id' => $job->getId()
        ]);
    }

    /**
     * @param Job $job
     */
    public function stopChildJob(Job $job, $status)
    {
        if ($job->isRoot()) {
            throw new \LogicException(sprintf('Can\'t stop root jobs. id: "%s"', $job->getId()));
        }

        if ($job->getStatus() !== Job::STATUS_RUNNING) {
            throw new \LogicException(sprintf(
                'Can stop only running jobs. id: "%s", status: "%s"',
                $job->getId(),
                $job->getStatus()
            ));
        }

        $validStopStatuses = [Job::STATUS_SUCCESS, Job::STATUS_FAILED, Job::STATUS_CANCELLED];
        if (! in_array($status, $validStopStatuses)) {
            throw new \LogicException(sprintf(
                'This status is not valid stop status. id: "%s", status: "%s", valid: [%s]',
                $job->getId(),
                $status,
                implode(', ', $validStopStatuses)
            ));
        }

        $job->setStatus($status);
        $job->setStoppedAt(new \DateTime());

        $this->jobStorage->saveJob($job);

        $this->producer->send(Topics::CALCULATE_ROOT_JOB_STATUS, [
            'id' => $job->getId()
        ]);
    }

    /**
     * @param Job  $job
     * @param bool $force
     */
    public function interruptRootJob(Job $job, $force = false)
    {
        if (! $job->isRoot()) {
            throw new \LogicException(sprintf('Can interrupt only root jobs. id: "%s"', $job->getId()));
        }

        if ($job->isInterrupted()) {
            return;
        }

        $this->jobStorage->saveJob($job, function (Job $job) use ($force) {
            if ($job->isInterrupted()) {
                return;
            }

            $job->setInterrupted(true);

            if ($force) {
                $job->setStoppedAt(new \DateTime());
            }
        });
    }
}
