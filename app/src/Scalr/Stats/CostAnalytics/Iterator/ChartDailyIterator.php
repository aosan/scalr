<?php

namespace Scalr\Stats\CostAnalytics\Iterator;

use Scalr\Stats\CostAnalytics\ChartPointInfo;
use \DateTime, \DateTimeZone;

/**
 * ChartDailyIterator
 *
 * This iterator is used to iterate over the date period
 * according to cost analytics data retention policy.
 *
 * @author   Vlad Dobrovolskiy  <v.dobrovolskiy@scalr.com>
 * @since    5.0 (03.12.2014)
 */
class ChartDailyIterator extends ChartPeriodIterator
{
    /**
     * Constructor
     *
     * @param   string       $start     The start date of the period 'YYYY-mm-dd'
     * @param   string       $end       optional End date
     * @param   string       $timezone  optional Timezone
     * @throws  \InvalidArgumentException
     */
    public function __construct($start, $end = null, $timezone = 'UTC')
    {
        $this->mode = 'day';
        $this->timezone = new DateTimeZone($timezone);
        $this->today = $this->getTodayDate();
        $this->start = new DateTime(($start instanceof DateTime ? $start->format('Y-m-d 00:00:00') : $start), $this->timezone);
        $this->end = (!empty($end) ? new DateTime(($end instanceof DateTime ? $end->format('Y-m-d 00:00:00') : $end), $this->timezone) : null);

        //Difference in days between Start and End dates
        $diffdays = $this->start->diff($this->end, true)->days;

        //Difference in days between Start and Today dates
        $diffTodayDays = $this->start->diff($this->today, true)->days;

        //Previous interval is the same period in the past
        $this->prevInterval = new \DateInterval('P' . ($diffdays + 1) . 'D');

        if ($diffdays < 2 && $diffTodayDays < 14) {
            $this->interval = '1 hour';
        } else {
            $this->interval = '1 day';
        }

        $this->prevStart = clone $this->start;
        $this->prevStart->sub($this->prevInterval);

        $this->wholePeriodPerviousEnd = clone $this->start;
        $this->wholePeriodPerviousEnd->modify('-1 day');

        $this->prevEnd = clone $this->prevStart;
        $this->prevEnd->add(new \DateInterval('P' . $this->start->diff(min($this->end, $this->today), true)->days . 'D'));

        $endoftheday = new \DateInterval('PT23H59M59S');

        $this->end->add($endoftheday);
        $this->prevEnd->add($endoftheday);
        $this->wholePeriodPerviousEnd->add($endoftheday);

        if (!$this->di)
            $this->di = \DateInterval::createFromDateString($this->interval);

        $this->dt = clone $this->start;
    }

    /**
     * {@inheritdoc}
     * @see Iterator::current()
     * @return ChartPointInfo
     */
    public function current()
    {
        if (!isset($this->c[$this->i])) {
            $chartPoint = new ChartPointInfo($this);
            $previousPeriodDt = clone $chartPoint->dt;
            $previousPeriodDt->sub($this->getPreviousPeriodInterval());

            if ($chartPoint->interval == '1 hour') {
                $h = $chartPoint->dt->format('H');
                $chartPoint->label = $chartPoint->dt->format('l, M j, g A');
                $chartPoint->show = $h == 0
                    ? $chartPoint->dt->format('M j')
                    : ($h % 3 == 0
                        ? $chartPoint->dt->format('g a')
                        : '');

                $chartPoint->key = $chartPoint->dt->format("Y-m-d H:00:00");
                $chartPoint->previousPeriodKey = $previousPeriodDt->format('Y-m-d H:00:00');
            } else if ($chartPoint->interval == '1 day') {
                $chartPoint->label = $chartPoint->dt->format('M j');

                $chartPoint->key = $chartPoint->dt->format('Y-m-d');
                $chartPoint->previousPeriodKey = $previousPeriodDt->format('Y-m-d');

                $chartPoint->show = $chartPoint->i % 4 == 0 || $chartPoint->isLastPoint && $chartPoint->i % 4 > 2
                    ? $chartPoint->dt->format('M j')
                    : '';
            }

            $this->c[$this->i] = $chartPoint;
        }

        return $this->c[$this->i];
    }
} 