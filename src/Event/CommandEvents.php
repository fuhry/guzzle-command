<?php
namespace GuzzleHttp\Command\Event;

use GuzzleHttp\Command\ServiceClientInterface;
use GuzzleHttp\Command\CanceledResponse;
use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Command\CommandTransaction;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\RequestEvents;

/**
 * Wraps HTTP lifecycle events with command lifecycle events.
 */
class CommandEvents
{
    /**
     * Creates a transactions and handles the workflow of a command before it
     * is sent.
     *
     * This includes preparing a request for the command, hooking the command
     * event system up to the request's event system, and returning the
     * prepared request.
     *
     * @param ServiceClientInterface  $client  Client to use in the transaction
     * @param CommandInterface        $command Command to execute
     *
     * @return CommandTransaction
     * @throws \RuntimeException
     */
    public static function prepareTransaction(
        ServiceClientInterface $client,
        CommandInterface $command
    ) {
        $trans = new CommandTransaction($client, $command);

        try {
            $ev = new PrepareEvent($trans);
            $trans->command->getEmitter()->emit('prepare', $ev);
        } catch (\Exception $e) {
            self::emitError($trans, $e);
            return $trans;
        }

        if ($ev->isPropagationStopped()) {
            // Event was intercepted with a result, so emit process.
            self::process($trans);
            return $trans;
        } elseif (!$trans->request) {
            throw new \RuntimeException('No request was prepared for the'
                . ' command and no result was added to intercept the event.'
                . ' One of the listeners must set a request in the prepare'
                . ' event.');
        }

        if ($future = $command->getFuture()) {
            $trans->request->getConfig()->set('future', $future);
        }

        self::injectErrorHandler($trans);

        // Process the command as soon as the request completes.
        $trans->request->getEmitter()->on(
            'complete',
            function (CompleteEvent $e) use ($trans) {
                $trans->response = $e->getResponse();
                self::process($trans);
            }
        );

        return $trans;
    }

    /**
     * Handles the processing workflow of a command after it has been sent.
     *
     * @param CommandTransaction $trans Command execution context
     * @throws \Exception
     */
    public static function process(CommandTransaction $trans)
    {
        try {
            $trans->command->getEmitter()->emit(
                'process',
                new ProcessEvent($trans)
            );
        } catch (\Exception $e) {
            self::emitError($trans, $e);
        }
    }

    /**
     * Emits an error event for the command.
     *
     * @param CommandTransaction $trans Command execution context
     * @param \Exception         $e     Exception encountered
     * @throws \Exception
     */
    public static function emitError(
        CommandTransaction $trans,
        \Exception $e
    ) {
        $trans->commandException = $e;
        $event = new CommandErrorEvent($trans);
        $trans->command->getEmitter()->emit('error', $event);

        if (!$event->isPropagationStopped()) {
            throw $e;
        }

        // It was intercepted, so remove it from the transaction.
        $trans->commandException = null;
    }

    /**
     * Wrap HTTP level errors with command level errors.
     */
    private static function injectErrorHandler(CommandTransaction $trans)
    {
        $trans->request->getEmitter()->on(
            'error',
            function (ErrorEvent $re) use ($trans) {
                $re->stopPropagation();
                $trans->commandException = self::exceptionFromError($trans, $re);
                $cev = new CommandErrorEvent($trans);
                $trans->command->getEmitter()->emit('error', $cev);
                if (!$cev->isPropagationStopped()) {
                    throw $trans->commandException;
                }
                $trans->commandException = null;
            },
            RequestEvents::LATE
        );
    }

    /**
     * Create a CommandException from a request error event.
     * @param CommandTransaction $trans
     * @param ErrorEvent         $re
     * @return \Exception
     */
    private static function exceptionFromError(
        CommandTransaction $trans,
        ErrorEvent $re
    ) {
        if ($response = $re->getResponse()) {
            $trans->response = $response;
        } else {
            self::stopRequestError($re);
        }

        return $trans->client->createCommandException(
            $trans,
            $re->getException()
        );
    }

    /**
     * Prevent a request from sending and intercept it's complete event.
     *
     * This method is required when a request fails before sending to prevent
     * adapters from still transferring the request over the wire.
     */
    private static function stopRequestError(ErrorEvent $e)
    {
        $fn = function ($ev) { $ev->stopPropagation(); };
        $e->getRequest()->getEmitter()->once('complete', $fn, 'first');
        $e->intercept(new CanceledResponse());
    }
}
