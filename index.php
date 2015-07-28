<?php
/**
 * JIRA Sprint Status Bar
 *
 * This script shows a simple status bar to analyze the status of a JIRA Agile sprint
 *
 * @author    Kai Mueller <kai.christian.mueller@gmail.com>
 */

/*
@todo move config to file
@todo move PHP to class
@todo move CSS to file
 */
?>
<!doctype html>
<html>
<head>
    <title>Sprint Status</title>
    <meta http-equiv="refresh" content="3600">
    <meta charset="utf-8">
</head>
<body>
<style type="text/css">
    html {
        font-family: arial, helvetica, sans-serif;
        margin:0px;
        padding:0px;
    }

    h1 {
        margin: 10px 0px;
    }

    h3 {
        font-size: 12pt;
        font-weight: normal;
    }

    .tiny {
        font-size:8pt;
    }

    .bug {
        color:red;
    }
    .closedBug {
        color:green;
    }

    #progressbar {
        background-color: black;
        border-radius: 16px;
        padding: 3px;
        color:#eee;
        float:left;
        width:100%;
    }

    #progressbar > div {
        height: 60px;
        line-height: 60px;
        font-size: 32pt;
        border-radius: 10px;
        text-align:center;
        float:left;
        font-weight:bold;
    }

    #progressbar .done {
        color:#000;
        background-color: greenyellow;
        border-radius: 13px 0px 0px 13px;
    }

    #progressbar .progress {
        color:#000;
        background-color: limegreen;
        border-radius: 0px 13px 13px 0px;
        background-image: -webkit-gradient(linear, 0 0, 100% 100%, color-stop(0.25, rgba(255, 255, 255, 0.2)), color-stop(0.25, transparent), color-stop(0.5, transparent), color-stop(0.5, rgba(255, 255, 255, 0.2)), color-stop(0.75, rgba(255, 255, 255, 0.2)), color-stop(0.75, transparent), to(transparent));
        background-image: -webkit-linear-gradient(45deg, rgba(255, 255, 255, 0.2) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.2) 75%, transparent 75%, transparent);
        background-image: -moz-linear-gradient(45deg, rgba(255, 255, 255, 0.2) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.2) 75%, transparent 75%, transparent);
        background-image: -ms-linear-gradient(45deg, rgba(255, 255, 255, 0.2) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.2) 75%, transparent 75%, transparent);
        background-image: -o-linear-gradient(45deg, rgba(255, 255, 255, 0.2) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.2) 75%, transparent 75%, transparent);
        -webkit-background-size: 45px 45px;
        -moz-background-size: 45px 45px;
        -o-background-size: 45px 45px;
        background-size: 45px 45px;
    }

    #progressbar .open {
        color:#fff;
        background-color: black;
    }

</style>
<?php

const STATUS_OPEN = 'open';
const STATUS_INPROGRESS = 'in progress';
const STATUS_DONE = 'done';

#######################################
# Config
#######################################

// JIRA API credentials
$api = array(
    'user' => '',
    'password' => '',
    'url' => 'https://MYDOMAIN.atlassian.net/'
);

// status name to progress mapper (STATUS_OPEN, STATUS_INPROGRESS, STATUS_DONE)
// you should add all possible JIRA states here
$statusMapper = array(
    'open' => STATUS_OPEN,
    'to do' => STATUS_OPEN,
    'in progress' => STATUS_INPROGRESS,
    'developed' => STATUS_DONE,
    'in testing' => STATUS_DONE,
    'tested' => STATUS_DONE,
    'merged' => STATUS_DONE,
    'closed' => STATUS_DONE
);

// a list of states that are completely ignored (for example already deployed stories)
$ignoreStates = array(
);

// ignore flagged issues (for example to exclude on hold or "stale" issues)
$ignoreFlagged = true;

// whether to show bug statistics
$showBugs = true;

// the issue type of bugs
$bugIssueType = 'Bug';

// The field name for estimated story points
$varCustomFieldEstimation = 'customfield_10004';


#######################################
# CURL
#######################################

$requestUrl = 'rest/greenhopper/1.0/xboard/work/allData/?rapidViewId=1';
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $api['url'] . $requestUrl);
curl_setopt($curl, CURLOPT_USERPWD, $api['user'] . ":" . $api['password']);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
$resp = curl_exec($curl);
curl_close($curl);
$data = json_decode($resp, true);


#######################################
# Logic
#######################################


function getStatus($jiraStatusName, $statusMapper) {
    if (array_key_exists($jiraStatusName, $statusMapper)) {
        return $statusMapper[$jiraStatusName];
    }
    return null;
}

// result array
$results = array(
    'storyPointsSum' => 0,
    'openStoryPoints' => 0,
    'inProgressStoryPoints' => 0,
    'developedStoryPoints' => 0,
    'openBugs' => 0,
    'closedBugs' => 0,
    'openStoryPointsPercent' => 0,
    'developedStoryPointsPercent' => 0,
    'inProgressStoryPointsPercent' => 0
);

$issues = $data['issuesData']['issues'];

// no open sprint or no issues
if (!isset($data['sprintsData']['sprints']) OR empty($data['sprintsData']['sprints']) OR empty($issues)) {
    echo '<h1>No open found</h1></body></html>';
    exit;
}

$processedIssues = array();

// process issues
foreach ($issues as $issue) {
    $status = strtolower($issue['statusName']);
    if (isset($issue['parentKey'])) {
        // add subtask to main issue
        $processedIssues[$issue['parentKey']]['subtasks'][$issue['key']] = $status;
    } else {
        // storyPoints
        // get estimated story points from main issue
        $storyPoints = null;
        if (isset($issue['estimateStatistic']['statFieldId']) AND $issue['estimateStatistic']['statFieldId'] == $varCustomFieldEstimation) {
            if (isset($issue['estimateStatistic']['statFieldValue']['value'])) {
                $storyPoints = (int) $issue['estimateStatistic']['statFieldValue']['value'];
            }
        }
        $issueData = array(
            'flagged'     => (isset($issue['flagged']) AND 1 == $issue['flagged']) ? 1 : 0,
            'storyPoints' => $storyPoints,
            'status'      => $status,
            'type'        => strtolower($issue['typeName'])
        );

        $processedIssues[$issue['key']] = $issueData;

    }
}

// storypoints calculation
foreach ($processedIssues as $issue) {

    // bugs
    if (strtolower($bugIssueType) == $issue['type']) {
        if ($showBugs) {
            if (STATUS_DONE == getStatus($issue['status'], $statusMapper)) {
                $results['closedBugs']++;
            } else {
                $results['openBugs']++;
            }
        }
        continue;
    }

    // ignore issues in some states
    if (in_array($issue['status'], $ignoreStates)) {
        continue;
    }

    // ignore flagged issues
    if ($ignoreFlagged AND 1 == $issue['flagged']) {
        continue;
    }

    // calculate story points
    if ($issue['storyPoints']) {

        $subtasksDone = false;
        $subtasksInProgress = false;

        // if issue has subtasks, process state from subtasks
        if (isset($issue['subtasks'])) {
            $subtasksDone = true;
            $subtasksInProgress = false;
            foreach ($issue['subtasks'] as $issueStatus) {
                if (STATUS_DONE != getStatus($issueStatus, $statusMapper)) {
                    $subtasksDone = false;
                }
                if (STATUS_INPROGRESS == getStatus($issueStatus, $statusMapper)) {
                    $subtasksInProgress = true;
                }
            }
        }

        // add story points
        if ($subtasksDone OR STATUS_DONE == getStatus($issue['status'], $statusMapper)) {
            $results['developedStoryPoints'] += $issue['storyPoints'];
        }
        if ($subtasksInProgress OR STATUS_INPROGRESS == getStatus($issue['status'], $statusMapper)) {
            $results['inProgressStoryPoints'] += $issue['storyPoints'];
        }
        if (!$subtasksDone AND !$subtasksInProgress AND STATUS_OPEN == getStatus($issue['status'], $statusMapper)) {
            $results['openStoryPoints'] += $issue['storyPoints'];
        }
    }
}

// statistics calculation
$results['storyPointsSum'] = $results['openStoryPoints'] + $results['developedStoryPoints'] + $results['inProgressStoryPoints'];
$results['openStoryPointsPercent'] = round($results['openStoryPoints'] / $results['storyPointsSum'] * 100);
$results['developedStoryPointsPercent'] = round($results['developedStoryPoints'] / $results['storyPointsSum'] * 100);
$results['inProgressStoryPointsPercent'] = round($results['inProgressStoryPoints'] / $results['storyPointsSum'] * 100);

?>
<h1 style="float:left"><?php echo $data['sprintsData']['sprints'][0]['name']; ?></h1>
<h3 style="float:right"><?php echo $data['sprintsData']['sprints'][0]['startDate']; ?> - <?php echo $data['sprintsData']['sprints'][0]['endDate']; ?></h3>
<div id="progressbar">
    <div class="done" style="width:<?php echo $results['developedStoryPointsPercent']?>%">
        <?php echo $results['developedStoryPoints']; ?><span class="tiny"> SP </span>
    </div>
    <div class="progress" style="width:<?php echo $results['inProgressStoryPointsPercent']?>%">
        <?php echo $results['inProgressStoryPoints']; ?><span class="tiny"> SP </span>
    </div>
    <div class="open" style="width:<?php echo $results['openStoryPointsPercent']?>%">
        <?php echo $results['openStoryPoints']; ?><span class="tiny"> SP </span>
    </div>
</div>
<?php if ($showBugs): ?>
<div style="font-size:14pt; float:left;">
    Bugs:
    <span style="font-size:28pt;">
        <?php for($i=1; $i<=$results['openBugs']; $i++) { echo '<span class="bug">●</span>'; } ?>
        <?php for($i=1; $i<=$results['closedBugs']; $i++) { echo '<span class="closedBug">●</span>'; } ?>
    </span>
</div>
<?php endif; ?>
</body>
</html>
