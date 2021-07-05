<?php

namespace Codeages\Biz\ItemBank\Answer\Service\Impl;

use Codeages\Biz\ItemBank\Answer\Dao\AnswerSceneDao;
use Codeages\Biz\ItemBank\Assessment\Service\AssessmentSectionItemService;
use Codeages\Biz\ItemBank\BaseService;
use Codeages\Biz\ItemBank\Answer\Service\AnswerSceneService;
use Codeages\Biz\ItemBank\ErrorCode;
use Codeages\Biz\ItemBank\Answer\Exception\AnswerSceneException;
use Codeages\Biz\ItemBank\Answer\Service\AnswerService;
use Codeages\Biz\ItemBank\Answer\Service\AnswerRecordService;
use Codeages\Biz\ItemBank\Answer\Service\AnswerReportService;
use Codeages\Biz\Framework\Util\ArrayToolkit;
use Codeages\Biz\ItemBank\Answer\Service\AnswerQuestionReportService;
use Codeages\Biz\ItemBank\Item\Service\ItemService;

class AnswerSceneServiceImpl extends BaseService implements AnswerSceneService
{
    public function count($conditions)
    {
        return $this->getAnswerSceneDao()->count($conditions);
    }

    public function search($conditions, $orderBys, $start, $limit, $columns = array())
    {
        return $this->getAnswerSceneDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function create($answerScene = array())
    {
        $answerScene = $this->validateAnswerScene($answerScene);
        $answerScene['created_user_id'] = $answerScene['updated_user_id'] = empty($this->biz['user']['id']) ? 0 : $this->biz['user']['id'];

        return $this->getAnswerSceneDao()->create($answerScene);
    }

    public function update($id, $answerScene = array())
    {
        if (empty($this->get($id))) {
            throw new AnswerSceneException('AnswerScene not found.', ErrorCode::ANSWER_SCENE_NOTFOUD);
        }

        $answerScene = $this->validateAnswerScene($answerScene);
        $answerScene['updated_user_id'] = empty($this->biz['user']['id']) ? 0 : $this->biz['user']['id'];

        return $this->getAnswerSceneDao()->update($id, $answerScene);
    }

    protected function validateAnswerScene($answerScene = array())
    {
        $answerScene = $this->getValidator()->validate($answerScene, [
            'name' => ['required'],
            'limited_time' => ['integer', ['min', 0]],
            'do_times' => ['integer', ['in', [0, 1]]],
            'redo_interval' => ['integer', ['min', 0]],
            'need_score' => ['integer', ['in', [0, 1]]],
            'manual_marking' => ['integer', ['in', [0, 1]]],
            'start_time' => ['integer'],
            'doing_look_analysis' => ['integer', ['in', [0, 1]]],
            'pass_score' => ['numeric', ['min', 0]],
            'enable_facein' => ['integer', ['in', [0, 1]]],
            'question_report_job_name' => [['lengthMax', 128]],
            'last_review_time' => ['integer'],
            'question_report_update_time' => ['integer'],
        ]);

        if (isset($answerScene['do_times']) && 1 == $answerScene['do_times']) {
            $answerScene['redo_interval'] = 0;
        }

        if (isset($answerScene['do_times']) && 0 == $answerScene['do_times']) {
            $answerScene['start_time'] = 0;
        }

        return $answerScene;
    }

    public function get($id)
    {
        return $this->getAnswerSceneDao()->get($id) ?: [];
    }

    public function canStart($id, $userId)
    {
        $answerScene = $this->get($id);
        if (empty($answerScene)) {
            return false;
        }

        if (0 != $answerScene['start_time'] && $answerScene['start_time'] > time()) {
            return false;
        }

        $latestAnswerRecord = $this->getAnswerRecordService()->getLatestAnswerRecordByAnswerSceneIdAndUserId($id, $userId);
        if ($latestAnswerRecord) {
            if (1 == $answerScene['do_times']) {
                return false;
            }

            if (0 < $answerScene['redo_interval']) {
                $answerReport = $this->getAnswerReportService()->getSimple($latestAnswerRecord['answer_report_id']);

                return $answerScene['redo_interval'] * 60 <= time() - $answerReport['review_time'];
            }
        }

        return true;
    }

    public function getAnswerSceneReport($id)
    {
//        $this->buildAnswerSceneReport($id);
        $answerReports = $this->getAnswerReportService()->findByAnswerSceneId($id);
        $answerRecords = $this->getAnswerRecordService()->findByAnswerSceneId($id);
        $answerSceneRerport = [
            'answer_scene_id' => $id,
            'joined_user_num' => $this->getJoinedUserNumByAnswerRecords($answerRecords),
            'finished_user_num' => $this->getFinishedUserNumByAnswerRecords($answerRecords),
            'avg_score' => $this->getAvgScoreByAnswerReports($answerReports),
            'max_score' => $this->getMaxScoreByAnswerReports($answerReports),
            'min_score' => $this->getMinScoreByAnswerReports($answerReports),
            'question_reports' => $this->getAnswerSceneQuestionReportDao()->findByAnswerSceneId($id)
        ];

        return $answerSceneRerport;
    }

    public function buildAnswerSceneReport($id)
    {
        $answerScene = $this->get($id);
        if (empty($answerScene)) {
            throw new AnswerSceneException('AnswerScene not found.', ErrorCode::ANSWER_SCENE_NOTFOUD);
        }

        $answerSceneQuestionReports = $this->getAnswerSceneQuestionReportsByAnswerSceneId($id);
        $oldAnswerSceneQuestionReports  = ArrayToolkit::index($this->getAnswerSceneQuestionReportDao()->findByAnswerSceneId($id), 'question_id');
        $createAnswerSceneQuestionReports = [];
        $updateAnswerSceneQuestionReports = [];
        foreach ($answerSceneQuestionReports as $questionId => $answerSceneQuestionReport) {
            if (isset($oldAnswerSceneQuestionReports[$questionId])) {
                $answerSceneQuestionReport['id'] = $oldAnswerSceneQuestionReports[$questionId]['id'];
                $updateAnswerSceneQuestionReports[] = $answerSceneQuestionReport;
            } else {
                $createAnswerSceneQuestionReports[] = $answerSceneQuestionReport;
            }
        }

        try {
            $this->beginTransaction();

            if ($createAnswerSceneQuestionReports) {
                $this->getAnswerSceneQuestionReportDao()->batchCreate($createAnswerSceneQuestionReports);
            }

            if ($updateAnswerSceneQuestionReports) {
                $this->getAnswerSceneQuestionReportDao()->batchUpdate(ArrayToolkit::column($updateAnswerSceneQuestionReports, 'id'), $updateAnswerSceneQuestionReports, 'id');
            }

            $this->update($answerScene['id'], ['name' => $answerScene['name'], 'question_report_update_time' => time()]);

            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function findNotStatisticsQuestionsReportScenes($limit = 100)
    {
        return $this->getAnswerSceneDao()->findNotStatisticsQuestionsReportScenes($limit);
    }

    protected function getAvgScoreByAnswerReports($answerReports)
    {
        if (empty($answerReports)) {
            return 0;
        }

        $scores = ArrayToolkit::column($answerReports, 'score');
        return sprintf("%.1f", array_sum($scores) / count($answerReports));
    }

    protected function getMaxScoreByAnswerReports($answerReports)
    {
        if (empty($answerReports)) {
            return 0;
        }

        $scores = ArrayToolkit::column($answerReports, 'score');
        rsort($scores);
        return sprintf("%.1f", $scores[0]);
    }

    protected function getMinScoreByAnswerReports($answerReports)
    {
        if (empty($answerReports)) {
            return 0;
        }

        $scores = ArrayToolkit::column($answerReports, 'score');
        sort($scores);
        return sprintf("%.1f", $scores[0]);
    }

    protected function getJoinedUserNumByAnswerRecords($answerRecords)
    {
        $userIds = [];
        foreach ($answerRecords as $record) {
            if (!in_array($record['user_id'], $userIds)) {
                $userIds[] = $record['user_id'];
            }
        }
        return count($userIds);
    }

    protected function getFinishedUserNumByAnswerRecords($answerRecords)
    {
        $userIds = [];
        foreach ($answerRecords as $record) {
            if (AnswerService::ANSWER_RECORD_STATUS_FINISHED == $record['status'] && !in_array($record['user_id'], $userIds)) {
                $userIds[] = $record['user_id'];
            }
        }
        return count($userIds);
    }

    protected function getAnswerSceneQuestionReportsByAnswerSceneId($answerSceneId)
    {
        $answerRecordIds = $this->getAnswerRecordIdsByAnswerSceneId($answerSceneId);
        if (empty($answerRecordIds)) {
            return [];
        }

        $record = $this->getAnswerRecordService()->get(current($answerRecordIds));
        $assessmentId = $record['assessment_id'];
        $itemIds = ArrayToolkit::column($this->getSectionItemService()->searchAssessmentSectionItems(
            ['assessmentId' => $assessmentId],
            [],
            0,
            $this->getSectionItemService()->countAssessmentSectionItems(['assessmentId' => $assessmentId]),
            ['item_id']
        ),'item_id');

        $answerSceneQuestionReports = [];
        $questions = $this->getItemService()->findQuestionsByItemIds($itemIds);
        foreach ($questions as $questionId => $question) {
            $reports = $this->getAnswerQuestionReportService()->search(
                ['question_id' => $question['id'], 'assessment_id' => $assessmentId, 'answer_record_ids' => $answerRecordIds],
                [],
                0,
                PHP_INT_MAX,
                ['status', 'response', 'question_id']
            );
            $answerSceneQuestionReport = $this->biz['answer_mode_factory']->create($question['answer_mode'])->getAnswerSceneQuestionReport($question, $reports);
            $answerSceneQuestionReport['answer_scene_id'] = $answerSceneId;
            $answerSceneQuestionReports[] = $answerSceneQuestionReport;
        }

        return ArrayToolkit::index($answerSceneQuestionReports, 'question_id');
    }

    protected function getQuestionReportsByAnswerRecordIds($answerRecordIds)
    {
        return ArrayToolkit::group($this->getAnswerQuestionReportService()->search(
            ['answer_record_ids' => $answerRecordIds],
            [],
            0,
            PHP_INT_MAX,
            ['status', 'response', 'question_id']
        ), 'question_id');
    }

    protected function getAnswerRecordIdsByAnswerSceneId($answerSceneId)
    {
        $conditions = ['answer_scene_id' => $answerSceneId, 'status' => AnswerService::ANSWER_RECORD_STATUS_FINISHED];
        $answerRecords = $this->getAnswerRecordService()->search(
            $conditions,
            [],
            0,
            $this->getAnswerRecordService()->count($conditions),
            ['id']
        );
        return  ArrayToolkit::column($answerRecords, 'id');
    }

    /**
     * @return AnswerReportService
     */
    protected function getAnswerReportService()
    {
        return $this->biz->service('ItemBank:Answer:AnswerReportService');
    }

    /**
     * @return AnswerRecordService
     */
    protected function getAnswerRecordService()
    {
        return $this->biz->service('ItemBank:Answer:AnswerRecordService');
    }

    /**
     * @return AnswerQuestionReportService
     */
    protected function getAnswerQuestionReportService()
    {
        return $this->biz->service('ItemBank:Answer:AnswerQuestionReportService');
    }

    /**
     * @return ItemService
     */
    protected function getItemService()
    {
        return $this->biz->service('ItemBank:Item:ItemService');
    }

    /**
     * @return AnswerSceneDao
     */
    protected function getAnswerSceneDao()
    {
        return $this->biz->dao('ItemBank:Answer:AnswerSceneDao');
    }

    protected function getAnswerSceneQuestionReportDao()
    {
        return $this->biz->dao('ItemBank:Answer:AnswerSceneQuestionReportDao');
    }

    /**
     * @return AssessmentSectionItemService
     */
    protected function getSectionItemService()
    {
        return $this->biz->service('ItemBank:Assessment:AssessmentSectionItemService');
    }
}
