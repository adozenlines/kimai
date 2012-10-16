<?php
/**
 * This file is part of
 * Kimai - Open Source Time Tracking // http://www.kimai.org
 * (c) 2006-2009 Kimai-Development-Team
 *
 * Kimai is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; Version 3, 29 June 2007
 *
 * Kimai is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Kimai; If not, see <http://www.gnu.org/licenses/>.
 */

include(WEBROOT.'libraries/mysql.class.php');

/**
 * Provides the database layer for MySQL.
 *
 * @author th
 * @author sl
 * @author Kevin Papst
 */
class MySQLDatabaseLayer extends DatabaseLayer {

  /**
   * Connect to the database.
   */
  public function connect($host,$database,$username,$password,$utf8,$serverType) {
    if (isset($utf8) && $utf8)
      $this->conn = new MySQL(true, $database, $host, $username, $password,"utf-8");
    else
      $this->conn = new MySQL(true, $database, $host, $username, $password);
  }

  private function logLastError($scope) {
      Logger::logfile($scope.': '.$this->conn->Error());
  }


  /**
  * Add a new customer to the database.
  *
  * @param array $data  name, address and other data of the new customer
  * @return int         the customerID of the new customer, false on failure
  * @author th
  */
  public function customer_create($data) {

      $data = $this->clean_data($data);

      $values     ['name']        =     MySQL::SQLValue($data   ['name']          );
      $values     ['comment']     =     MySQL::SQLValue($data   ['comment']       );
      if (isset($data['password']))
        $values   ['password']    =     MySQL::SQLValue($data   ['password']      );
      else
        $values   ['password']    =     "''";
      $values     ['company']     =     MySQL::SQLValue($data   ['company']       );
      $values     ['vat']         =     MySQL::SQLValue($data   ['vat']           );
      $values     ['contact']     =     MySQL::SQLValue($data   ['contact']       );
      $values     ['street']      =     MySQL::SQLValue($data   ['street']        );
      $values     ['zipcode']     =     MySQL::SQLValue($data   ['zipcode']       );
      $values     ['city']        =     MySQL::SQLValue($data   ['city']          );
      $values     ['phone']       =     MySQL::SQLValue($data   ['phone']         );
      $values     ['fax']         =     MySQL::SQLValue($data   ['fax']           );
      $values     ['mobile']      =     MySQL::SQLValue($data   ['mobile']        );
      $values     ['mail']        =     MySQL::SQLValue($data   ['mail']          );
      $values     ['homepage']    =     MySQL::SQLValue($data   ['homepage']      );
      $values     ['timezone']    =     MySQL::SQLValue($data   ['timezone']      );

      $values['visible'] = MySQL::SQLValue($data['visible'] , MySQL::SQLVALUE_NUMBER  );
      $values['filter']  = MySQL::SQLValue($data['filter']  , MySQL::SQLVALUE_NUMBER  );

      $table = $this->getCustomerTable();
      $result = $this->conn->InsertRow($table, $values);

      if (! $result) {
        $this->logLastError('customer_create');
        return false;
      } else {
        return $this->conn->GetLastInsertID();
      }
  }

  /**
  * Returns the data of a certain customer
  *
  * @param array $customerID  id of the customer
  * @return array         the customer's data (name, address etc) as array, false on failure
  * @author th
  */
  public function customer_get_data($customerID) {
      $filter['customerID'] = MySQL::SQLValue($customerID, MySQL::SQLVALUE_NUMBER);
      $table = $this->getCustomerTable();
      $result = $this->conn->SelectRows($table, $filter);

      if (! $result) {
        $this->logLastError('customer_get_data');
        return false;
      } else {
          return $this->conn->RowArray(0,MYSQL_ASSOC);
      }
  }

  /**
  * Edits a customer by replacing his data by the new array
  *
  * @param int $customerID  id of the customer to be edited
  * @param array $data    name, address and other new data of the customer
  * @return boolean       true on success, false on failure
  * @author ob/th
  */
  public function customer_edit($customerID, $data) {
      $data = $this->clean_data($data);

      $values = array();

      $strings = array(
        'name'    ,'comment','password' ,'company','vat',
        'contact' ,'street' ,'zipcode'  ,'city'   ,'phone',
        'fax'     ,'mobile' ,'mail'     ,'homepage', 'timezone');
      foreach ($strings as $key) {
        if (isset($data[$key]))
          $values[$key] = MySQL::SQLValue($data[$key]);
      }

      $numbers = array('visible','filter');
      foreach ($numbers as $key) {
        if (isset($data[$key]))
          $values[$key] = MySQL::SQLValue($data[$key] , MySQL::SQLVALUE_NUMBER );
      }

      $filter['customerID']       = MySQL::SQLValue($customerID, MySQL::SQLVALUE_NUMBER);

      $table = $this->getCustomerTable();
      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      return $this->conn->Query($query);
  }

  /**
  * Assigns a customer to 1-n groups by adding entries to the cross table
  *
  * @param int $customerID     id of the customer to which the groups will be assigned
  * @param array $groupIDs    contains one or more groupIDs
  * @return boolean            true on success, false on failure
  * @author ob/th
  */
  public function assign_customerToGroups($customerID, $groupIDs) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_customerToGroups');
        return false;
      }

      $table = $this->kga['server_prefix']."groups_customers";
      $filter['customerID'] = MySQL::SQLValue($customerID, MySQL::SQLVALUE_NUMBER);
      $d_query = MySQL::BuildSQLDelete($table, $filter);
      $d_result = $this->conn->Query($d_query);

      if ($d_result == false) {
              $this->logLastError('assign_customerToGroups');
              $this->conn->TransactionRollback();
              return false;
      }

      foreach ($groupIDs as $groupID) {
          $values['groupID'] = MySQL::SQLValue($groupID , MySQL::SQLVALUE_NUMBER);
          $values['customerID'] = MySQL::SQLValue($customerID      , MySQL::SQLVALUE_NUMBER);
          $query = MySQL::BuildSQLInsert($table, $values);
          $result = $this->conn->Query($query);

          if ($result == false) {
                  $this->logLastError('assign_customerToGroups');
                  $this->conn->TransactionRollback();
                  return false;
          }
      }

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_customerToGroups');
          return false;
      }
  }

  /**
  * returns all IDs of the groups of the given customer
  *
  * @param int $id  id of the customer
  * @return array         contains the groupIDs of the groups or false on error
  * @author th
  */
  public function customer_get_groupIDs($customerID) {
      $filter['customerID'] = MySQL::SQLValue($customerID, MySQL::SQLVALUE_NUMBER);
      $columns[]        = "groupID";
      $table = $this->kga['server_prefix']."groups_customers";

      $result = $this->conn->SelectRows($table, $filter, $columns);
      if ($result == false) {
          return false;
      }

      $groupIDs = array();
      $counter     = 0;

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);

      if ($this->conn->RowCount()) {
          foreach ($rows as $row) {
              $groupIDs[$counter] = $row['groupID'];
              $counter++;
          }
          return $groupIDs;
      } else {
          $this->logLastError('customer_get_groupIDs');
          return false;
      }
  }

  /**
  * deletes a customer
  *
  * @param int $customerID  id of the customer
  * @return boolean       true on success, false on failure
  * @author th
  */
  public function customer_delete($customerID) {
      $values['trash'] = 1;
      $filter['customerID'] = MySQL::SQLValue($customerID, MySQL::SQLVALUE_NUMBER);
      $table = $this->getCustomerTable();

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);
      return $this->conn->Query($query);
  }

  /**
  * Adds a new project
  *
  * @param array $data  name, comment and other data of the new project
  * @return int         the ID of the new project, false on failure
  * @author th
  */
  public function project_create($data) {
      $data = $this->clean_data($data);

      $values['name']       = MySQL::SQLValue($data['name']    );
      $values['comment']    = MySQL::SQLValue($data['comment'] );
      $values['budget']     = MySQL::SQLValue($data['budget']    , MySQL::SQLVALUE_NUMBER );
      $values['effort']     = MySQL::SQLValue($data['effort']    , MySQL::SQLVALUE_NUMBER );
      $values['approved']   = MySQL::SQLValue($data['approved']  , MySQL::SQLVALUE_NUMBER );
      $values['customerID'] = MySQL::SQLValue($data['customerID'], MySQL::SQLVALUE_NUMBER );
      $values['visible']    = MySQL::SQLValue($data['visible']   , MySQL::SQLVALUE_NUMBER );
      $values['internal']   = MySQL::SQLValue($data['internal']  , MySQL::SQLVALUE_NUMBER );
      $values['filter']     = MySQL::SQLValue($data['filter']    , MySQL::SQLVALUE_NUMBER );

      $table = $this->kga['server_prefix']."projects";
      $result = $this->conn->InsertRow($table, $values);

      if (! $result) {
        $this->logLastError('project_create');
        return false;
      }

      $projectID = $this->conn->GetLastInsertID();

      if (isset($data['defaultRate'])) {
        if (is_numeric($data['defaultRate']))
          $this->save_rate(NULL,$projectID,NULL,$data['defaultRate']);
        else
          $this->remove_rate(NULL,$projectID,NULL);
      }

      if (isset($data['myRate'])) {
        if (is_numeric($data['myRate']))
          $this->save_rate($this->kga['user']['userID'],$projectID,NULL,$data['myRate']);
        else
          $this->remove_rate($this->kga['user']['userID'],$projectID,NULL);
      }

      if (isset($data['fixedRate'])) {
        if (is_numeric($data['fixedRate']))
          $this->save_fixed_rate($projectID,NULL,$data['fixedRate']);
        else
          $this->remove_fixed_rate($projectID,NULL);
      }

      return $projectID;
  }

  /**
  * Returns the data of a certain project
  *
  * @param int $projectID ID of the project

  * @return array         the project's data (name, comment etc) as array, false on failure
  * @author th
  */
  public function project_get_data($projectID) {
      if (!is_numeric($projectID)) {
          return false;
      }

      $filter['projectID'] = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);
      $table = $this->getProjectTable();
      $result = $this->conn->SelectRows($table, $filter);

      if (! $result) {
        $this->logLastError('project_get_data');
        return false;
      }

      $result_array = $this->conn->RowArray(0,MYSQL_ASSOC);
      $result_array['defaultRate'] = $this->get_rate(NULL,$projectID,NULL);
      $result_array['myRate'] = $this->get_rate($this->kga['user']['userID'],$projectID,NULL);
      $result_array['fixedRate'] = $this->get_fixed_rate($projectID,NULL);
      return $result_array;
  }

  /**
  * Edits a project by replacing its data by the new array
  *
  * @param int $projectID   id of the project to be edited
  * @param array $data     name, comment and other new data of the project
  * @return boolean        true on success, false on failure
  * @author ob/th
  */
  public function project_edit($projectID, $data) {
      $data = $this->clean_data($data);

      $strings = array('name', 'comment');
      foreach ($strings as $key) {
        if (isset($data[$key]))
          $values[$key] = MySQL::SQLValue($data[$key]);
      }

      $numbers = array(
      'budget', 'customerID', 'visible', 'internal', 'filter', 'effort', 'approved');
      foreach ($numbers as $key) {
        if (isset($data[$key]))
          $values[$key] = MySQL::SQLValue($data[$key] , MySQL::SQLVALUE_NUMBER );
      }

      $filter ['projectID'] = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."projects";


      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('project_edit');
        return false;
      }

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      if ($this->conn->Query($query)) {

          if (isset($data['defaultRate'])) {
            if (is_numeric($data['defaultRate']))
              $this->save_rate(NULL,$projectID,NULL,$data['defaultRate']);
            else
              $this->remove_rate(NULL,$projectID,NULL);
          }

          if (isset($data['myRate'])) {
            if (is_numeric($data['myRate']))
              $this->save_rate($this->kga['user']['userID'],$projectID,NULL,$data['myRate']);
            else
              $this->remove_rate($this->kga['user']['userID'],$projectID,NULL);
          }

          if (isset($data['fixedRate'])) {
            if (is_numeric($data['fixedRate']))
              $this->save_fixed_rate($projectID,NULL,$data['fixedRate']);
            else
              $this->remove_fixed_rate($projectID,NULL);
          }

          if (! $this->conn->TransactionEnd()) {
            $this->logLastError('project_edit');
            return false;
          }
          return true;
      } else {
          $this->logLastError('project_edit');
          if (! $this->conn->TransactionRollback()) {
            $this->logLastError('project_edit');
            return false;
          }
          return false;
      }
  }

  /**
  * Assigns a project to 1-n groups by adding entries to the cross table
  *
  * @param int $projectID        ID of the project to which the groups will be assigned
  * @param array $groupIDs    contains one or more groupIDs
  * @return boolean            true on success, false on failure
  * @author ob/th
  */
  public function assign_projectToGroups($projectID, $groupIDs) {


      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_projectToGroups');
        return false;
      }

      $table = $this->kga['server_prefix']."groups_projects";
      $filter['projectID'] = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);
      $d_query = MySQL::BuildSQLDelete($table, $filter);
      $d_result = $this->conn->Query($d_query);

      if ($d_result == false) {
              $this->logLastError('assign_projectToGroups');
              $this->conn->TransactionRollback();
              return false;
      }

      foreach ($groupIDs as $groupID) {

        $values['groupID']   = MySQL::SQLValue($groupID , MySQL::SQLVALUE_NUMBER);
        $values['projectID']   = MySQL::SQLValue($projectID      , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);
        $result = $this->conn->Query($query);

        if ($result == false) {
                $this->logLastError('assign_projectToGroups');
                $this->conn->TransactionRollback();
                return false;
        }
      }

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_projectToGroups');
          return false;
      }
  }

  /**
  * returns all the groups of the given project
  *
  * @param array $projectID  ID of the project
  * @return array         contains the groupIDs of the groups or false on error
  * @author th
  */
  public function project_get_groupIDs($projectID) {


      $filter['projectID'] = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);
      $columns[]        = "groupID";
      $table = $this->kga['server_prefix']."groups_projects";

      $result = $this->conn->SelectRows($table, $filter, $columns);
      if ($result == false) {
          $this->logLastError('project_get_groupIDs');
          return false;
      }

      $groupIDs = array();
      $counter     = 0;

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);

      if ($this->conn->RowCount()) {
          foreach ($rows as $row) {
              $groupIDs[$counter] = $row['groupID'];
              $counter++;
          }
          return $groupIDs;
      } else {
          return false;
      }
  }

  /**
  * deletes a project
  *
  * @param array $projectID  ID of the project
  * @return boolean       true on success, false on failure
  * @author th
  */
  public function project_delete($projectID) {


      $values['trash'] = 1;
      $filter['projectID'] = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);
      $table = $this->getProjectTable();

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);
      return $this->conn->Query($query);
  }

  /**
  * Adds a new activity
  *
  * @param array $data   name, comment and other data of the new activity
  * @return int          the activityID of the new project, false on failure
  * @author th
  */
  public function activity_create($data) {
      $data = $this->clean_data($data);

      $values['name']    = MySQL::SQLValue($data['name']    );
      $values['comment'] = MySQL::SQLValue($data['comment'] );
      $values['visible'] = MySQL::SQLValue($data['visible'] , MySQL::SQLVALUE_NUMBER );
      $values['filter']  = MySQL::SQLValue($data['filter']  , MySQL::SQLVALUE_NUMBER );
      $values['assignable'] = MySQL::SQLValue($data['assignable']  , MySQL::SQLVALUE_NUMBER );

      $table =  $this->getActivityTable();
      $result = $this->conn->InsertRow($table, $values);

      if (! $result) {
        $this->logLastError('activity_create');
        return false;
      }

      $activityID = $this->conn->GetLastInsertID();

      if (isset($data['defaultRate'])) {
        if (is_numeric($data['defaultRate']))
          $this->save_rate(NULL,NULL,$activityID,$data['defaultRate']);
        else
          $this->remove_rate(NULL,NULL,$activityID);
      }

      if (isset($data['myRate'])) {
        if (is_numeric($data['myRate']))
          $this->save_rate($this->kga['user']['userID'],NULL,$activityID,$data['myRate']);
        else
          $this->remove_rate($this->kga['user']['userID'],NULL,$activityID);
      }

      if (isset($data['fixedRate'])) {
        if (is_numeric($data['fixedRate']))
          $this->save_fixed_rate(NULL,$activityID,$data['fixedRate']);
        else
          $this->remove_fixed_rate(NULL,$activityID);
      }

      return $activityID;
  }

  /**
  * Returns the data of a certain task
  *
  * @param array $activityID  activityID of the project
  * @return array         the activity's data (name, comment etc) as array, false on failure
  * @author th
  */
  public function activity_get_data($activityID) {
      $filter['activityID'] = MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."activities";
      $result = $this->conn->SelectRows($table, $filter);

      if (! $result) {
        $this->logLastError('activity_get_data');
        return false;
      }


      $result_array = $this->conn->RowArray(0,MYSQL_ASSOC);

      $result_array['defaultRate'] = $this->get_rate(NULL,NULL,$result_array['activityID']);
      $result_array['myRate'] = $this->get_rate($this->kga['user']['userID'],NULL,$result_array['activityID']);
      $result_array['fixedRate'] = $this->get_fixed_rate(NULL,$result_array['activityID']);

      return $result_array;
  }

  /**
  * Edits an activity by replacing its data by the new array
  *
  * @param array $activityID  activityID of the project to be edited
  * @param array $data    name, comment and other new data of the activity
  * @return boolean       true on success, false on failure
  * @author th
  */
  public function activity_edit($activityID, $data) {


      $data = $this->clean_data($data);


      $strings = array('name', 'comment');
      foreach ($strings as $key) {
        if (isset($data[$key]))
          $values[$key] = MySQL::SQLValue($data[$key]);
      }

      $numbers = array('visible', 'filter', 'assignable');
      foreach ($numbers as $key) {
        if (isset($data[$key]))
          $values[$key] = MySQL::SQLValue($data[$key] , MySQL::SQLVALUE_NUMBER );
      }

      $filter  ['activityID']          =   MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER);
      $table = $this->getActivityTable();

      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('activity_edit');
        return false;
      }

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      if ($this->conn->Query($query)) {

          if (isset($data['defaultRate'])) {
            if (is_numeric($data['defaultRate']))
              $this->save_rate(NULL,NULL,$activityID,$data['defaultRate']);
            else
              $this->remove_rate(NULL,NULL,$activityID);
          }

          if (isset($data['myRate'])) {
            if (is_numeric($data['myRate']))
              $this->save_rate($this->kga['user']['userID'],NULL,$activityID,$data['myRate']);
            else
              $this->remove_rate($this->kga['user']['userID'],NULL,$activityID);
          }

          if (isset($data['fixedRate'])) {
            if (is_numeric($data['fixedRate']))
              $this->save_fixed_rate(NULL,$activityID,$data['fixedRate']);
            else
              $this->remove_fixed_rate(NULL,$activityID);
          }

          if (! $this->conn->TransactionEnd()) {
            $this->logLastError('activity_edit');
            return false;
          }
          return true;
      } else {
          $this->logLastError('activity_edit');
          if (! $this->conn->TransactionRollback()) {
            $this->logLastError('activity_edit');
            return false;
          }
          return false;
      }
  }

  /**
  * Assigns an activity to 1-n groups by adding entries to the cross table
  *
  * @param int $activityID         activityID of the project to which the groups will be assigned
  * @param array $groupIDs    contains one or more groupIDs
  * @return boolean            true on success, false on failure
  * @author ob/th
  */
  public function assign_activityToGroups($activityID, $groupIDs) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_activityToGroups');
        return false;
      }

      $table = $this->kga['server_prefix']."groups_activities";
      $filter['activityID'] = MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER);
      $d_query = MySQL::BuildSQLDelete($table, $filter);
      $d_result = $this->conn->Query($d_query);

      if ($d_result == false) {
          $this->logLastError('assign_activityToGroups');
          $this->conn->TransactionRollback();
          return false;
      }

      foreach ($groupIDs as $groupID) {
        $values['groupID'] = MySQL::SQLValue($groupID , MySQL::SQLVALUE_NUMBER);
        $values['activityID'] = MySQL::SQLValue($activityID      , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);
        $result = $this->conn->Query($query);

        if ($result == false) {
            $this->logLastError('assign_activityToGroups');
            $this->conn->TransactionRollback();
            return false;
        }
      }

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_activityToGroups');
          return false;
      }
  }

  /**
  * Assigns an activity to 1-n projects by adding entries to the cross table
  *
  * @param int $activityID         id of the activity to which projects will be assigned
  * @param array $projectIDs    contains one or more projectIDs
  * @return boolean            true on success, false on failure
  * @author ob/th
  */
  public function assign_activityToProjects($activityID, $projectIDs) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_activityToProjects');
        return false;
      }

      $table = $this->kga['server_prefix']."projects_activities";
      $filter['activityID'] = MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER);
      $d_query = MySQL::BuildSQLDelete($table, $filter);
      $d_result = $this->conn->Query($d_query);

      if ($d_result == false) {
          $this->logLastError('assign_activityToProjects');
          $this->conn->TransactionRollback();
          return false;
      }

      foreach ($projectIDs as $projectID) {
        $values['projectID'] = MySQL::SQLValue($projectID , MySQL::SQLVALUE_NUMBER);
        $values['activityID'] = MySQL::SQLValue($activityID      , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);
        $result = $this->conn->Query($query);

        if ($result == false) {
            $this->logLastError('assign_activityToProjects');
            $this->conn->TransactionRollback();
            return false;
        }
      }

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_activityToProjects');
          return false;
      }
  }

  /**
  * Assigns 1-n activities to a project by adding entries to the cross table
  *
  * @param int $projectID         id of the project to which activities will be assigned
  * @param array $activityID    contains one or more activityIDs
  * @return boolean            true on success, false on failure
  * @author sl
  */
  public function assign_projectToActivities($projectID, $activityIDs) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_projectToActivities');
        return false;
      }

      $table = $this->kga['server_prefix']."projects_activities";
      $filter['projectID'] = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);
      $d_query = MySQL::BuildSQLDelete($table, $filter);
      $d_result = $this->conn->Query($d_query);

      if ($d_result == false) {
          $this->logLastError('assign_projectToActivities');
          $this->conn->TransactionRollback();
          return false;
      }

      foreach ($activityIDs as $activityID) {
        $values['activityID'] = MySQL::SQLValue($activityID , MySQL::SQLVALUE_NUMBER);
        $values['projectID'] = MySQL::SQLValue($projectID   , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);
        $result = $this->conn->Query($query);

        if ($result == false) {
            $this->logLastError('assign_projectToActivities');
            $this->conn->TransactionRollback();
            return false;
        }
      }

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_projectToActivities');
          return false;
      }
  }

  /**
  * returns all the projects to which the activity was assigned
  *
  * @param array $activityID  activityID of the project
  * @return array         contains the IDs of the projects or false on error
  * @author th
  */
  public function activity_get_projects($activityID) {
      $filter ['activityID'] = MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER);
      $columns[]         = "projectID";
      $table = $this->kga['server_prefix']."projects_activities";

      $result = $this->conn->SelectRows($table, $filter, $columns);
      if ($result == false) {
          $this->logLastError('activity_get_projects');
          return false;
      }

      $groupIDs = array();
      $counter     = 0;

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);

      if ($this->conn->RowCount()) {
          foreach ($rows as $row) {
              $groupIDs[$counter] = $row['projectID'];
              $counter++;
          }
      }
      return $groupIDs;
  }

  /**
   *
   * update the data for activity per project, which is budget, approved and effort
   * @param integer $projectID
   * @param integer $activityID
   * @param array $data
   */
  public function project_activity_edit($projectID, $activityID, $data) {

      $data = $this->clean_data($data);

      $filter  ['projectID']          =   MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);
      $filter  ['activityID']          =   MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."projects_activities";

      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('project_activity_edit');
        return false;
      }

      $query = MySQL::BuildSQLUpdate($table, $data, $filter);
      if ($this->conn->Query($query)) {

          if (! $this->conn->TransactionEnd()) {
            $this->logLastError('project_activity_edit');
            return false;
          }
          return true;
      } else {
          $this->logLastError('project_activity_edit');
          if (! $this->conn->TransactionRollback()) {
            $this->logLastError('project_activity_edit');
            return false;
          }
          return false;
      }
  }

  /**
  * returns all the activities which were assigned to a project
  *
  * @param integer $projectID  ID of the project
  * @return array         contains the activityIDs of the activities or false on error
  * @author sl
  */
  public function project_get_activities($projectID) {
      $projectId = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);
      $p = $this->kga['server_prefix'];

      $query = "SELECT activity.*, activityID, budget, effort, approved
                FROM ${p}projects_activities AS p_a
                JOIN ${p}activities AS activity USING(activityID)
                WHERE projectID = $projectId AND activity.trash=0;";

      $result = $this->conn->Query($query);

      if ($result == false) {
          $this->logLastError('project_get_activities');
          return false;
      }

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);
      return $rows;
  }

  /**
  * returns all the groups of the given activity
  *
  * @param array $activityID  activityID of the project
  * @return array         contains the groupIDs of the groups or false on error
  * @author th
  */
  public function activity_get_groups($activityID) {
      $filter ['activityID'] = MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER);
      $columns[]         = "groupID";
      $table = $this->kga['server_prefix']."groups_activities";

      $result = $this->conn->SelectRows($table, $filter, $columns);
      if ($result == false) {
          $this->logLastError('activity_get_groups');
          return false;
      }

      $groupIDs = array();
      $counter     = 0;

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);

      if ($this->conn->RowCount()) {
          foreach ($rows as $row) {
              $groupIDs[$counter] = $row['groupID'];
              $counter++;
          }
          return $groupIDs;
      } else {
          return false;
      }
  }

  /**
  * deletes an activity
  *
  * @param array $activityID  activityID of the activity
  * @return boolean       true on success, false on failure
  * @author th
  */
  public function activity_delete($activityID) {


      $values['trash'] = 1;
      $filter['activityID'] = MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER);
      $table = $this->getActivityTable();

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);
      return $this->conn->Query($query);
  }

  /**
  * Assigns a group to 1-n customers by adding entries to the cross table
  * (counterpart to assign_customerToGroups)
  *
  * @param array $groupID      ID of the group to which the customers will be assigned
  * @param array $customerIDs  contains one or more IDs of customers
  * @return boolean            true on success, false on failure
  * @author ob/th
  */
  public function assign_groupToCustomers($groupID, $customerIDs) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_groupToCustomers');
        return false;
      }

      $table = $this->kga['server_prefix']."groups_customers";
      $filter['groupID'] = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $d_query = MySQL::BuildSQLDelete($table, $filter);

      $d_result = $this->conn->Query($d_query);

      if ($d_result == false) {
              $this->logLastError('assign_groupToCustomers');
              $this->conn->TransactionRollback();
              return false;
      }

      foreach ($customerIDs as $customerID) {
        $values['groupID']       = MySQL::SQLValue($groupID      , MySQL::SQLVALUE_NUMBER);
        $values['customerID']       = MySQL::SQLValue($customerID , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);
        $result = $this->conn->Query($query);

        if ($result == false) {
                $this->logLastError('assign_groupToCustomers');
                $this->conn->TransactionRollback();
                return false;
        }
      }

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_groupToCustomers');
          return false;
      }
  }

  /**
  * Assigns a group to 1-n projects by adding entries to the cross table
  * (counterpart to assign_projectToGroups)
  *
  * @param array $groupID        groupID of the group to which the projects will be assigned
  * @param array $projectIDs    contains one or more project IDs
  * @return boolean            true on success, false on failure
  * @author ob
  */
  public function assign_groupToProjects($groupID, $projectIDs) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_groupToProjects');
        return false;
      }

      $table = $this->kga['server_prefix']."groups_projects";
      $filter['groupID'] = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $d_query = MySQL::BuildSQLDelete($table, $filter);
      $d_result = $this->conn->Query($d_query);

      if ($d_result == false) {
              $this->logLastError('assign_groupToProjects');
              $this->conn->TransactionRollback();
              return false;
      }

      foreach ($projectIDs as $projectID) {
        $values['groupID'] = MySQL::SQLValue($groupID      , MySQL::SQLVALUE_NUMBER);
        $values['projectID'] = MySQL::SQLValue($projectID , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);
        $result = $this->conn->Query($query);

        if ($result == false) {
            $this->logLastError('assign_groupToProjects');
            $this->conn->TransactionRollback();
            return false;
        }
      }

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_groupToProjects');
          return false;
      }
  }

  /**
  * Assigns a group to 1-n activities by adding entries to the cross table
  * (counterpart to assign_activityToGroups)
  *
  * @param array $groupID        groupID of the group to which the activities will be assigned
  * @param array $activityID    contains one or more activityIDs
  * @return boolean            true on success, false on failure
  * @author ob
  */
  public function assign_groupToActivities($groupID, $activityID) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_groupToActivities');
        return false;
      }

      $table = $this->kga['server_prefix']."groups_activities";
      $filter['groupID'] = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $d_query = MySQL::BuildSQLDelete($table, $filter);
      $d_result = $this->conn->Query($d_query);

      if ($d_result == false) {
          $this->logLastError('assign_groupToActivities');
          $this->conn->TransactionRollback();
          return false;
      }

      foreach ($activityID as $activityID) {
        $values['groupID'] = MySQL::SQLValue($groupID      , MySQL::SQLVALUE_NUMBER);
        $values['activityID'] = MySQL::SQLValue($activityID , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);
        $result = $this->conn->Query($query);

        if ($result == false) {
            $this->logLastError('assign_groupToActivities');
            $this->conn->TransactionRollback();
            return false;
        }
      }

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_groupToActivities');
          return false;
      }
  }

  /**
  * Adds a new user
  *
  * @param array $data  username, email, and other data of the new user
  * @return boolean|integer     false on failure, otherwise the new user id
  * @author th
  */
  public function user_create($data) {
      // find random but unused user id
      do {
        $data['userID'] = random_number(9);
      } while ($this->user_get_data($data['userID']));

      $data = $this->clean_data($data);

      $values ['name']   = MySQL::SQLValue($data['name']);
      $values ['userID']     = MySQL::SQLValue($data['userID']    , MySQL::SQLVALUE_NUMBER);
      $values ['status']    = MySQL::SQLValue($data['status']   , MySQL::SQLVALUE_NUMBER);
      $values ['active'] = MySQL::SQLValue($data['active'], MySQL::SQLVALUE_NUMBER);

      $table  = $this->kga['server_prefix']."users";
      $result = $this->conn->InsertRow($table, $values);

      if ($result===false) {
        $this->logLastError('user_create');
        return false;
      }

      if (isset($data['rate'])) {
        if (is_numeric($data['rate'])) {
          $this->save_rate($data['userID'], NULL, NULL, $data['rate']);
        } else {
          $this->remove_rate($data['userID'], NULL, NULL);
        }
      }
    
      return $data['userID'];
  }

  /**
  * Returns the data of a certain user
  *
  * @param array $userID  ID of the user
  * @return array         the user's data (username, email-address, status etc) as array, false on failure
  * @author th
  */
  public function user_get_data($userID)
  {
      $filter['userID'] = MySQL::SQLValue($userID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."users";
      $result = $this->conn->SelectRows($table, $filter);

      if (!$result) {
        $this->logLastError('user_get_data');
        return false;
      }

      // return  $this->conn->getHTML();
      return $this->conn->RowArray(0,MYSQL_ASSOC);
  }

  /**
  * Edits a user by replacing his data and preferences by the new array
  *
  * @param array $userID  userID of the user to be edited
  * @param array $data    username, email, and other new data of the user
  * @return boolean       true on success, false on failure
  * @author ob/th
  */
  public function user_edit($userID, $data)
  {
      $data = $this->clean_data($data);
      $strings = array('name', 'mail', 'alias', 'password', 'apikey');
      $values = array();

      foreach ($strings as $key) {
        if (isset($data[$key])) {
          $values[$key] = MySQL::SQLValue($data[$key]);
        }
      }

      $numbers = array('status' ,'trash' ,'active', 'lastProject' ,'lastActivity' ,'lastRecord');
      foreach ($numbers as $key) {
        if (isset($data[$key]))
          $values[$key] = MySQL::SQLValue($data[$key] , MySQL::SQLVALUE_NUMBER );
      }

      $filter['userID'] = MySQL::SQLValue($userID, MySQL::SQLVALUE_NUMBER);
      $table            = $this->getUserTable();

      if (!$this->conn->TransactionBegin()) {
        $this->logLastError('user_edit');
        return false;
      }

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      if ($this->conn->Query($query))
      {
          if (isset($data['rate'])) {
            if (is_numeric($data['rate'])) {
              $this->save_rate($userID,NULL,NULL,$data['rate']);
            } else {
              $this->remove_rate($userID,NULL,NULL);
            }
          }

          if (! $this->conn->TransactionEnd()) {
            $this->logLastError('user_edit');
            return false;
          }

          return true;
      }

      if (!$this->conn->TransactionRollback()) {
        $this->logLastError('user_edit');
        return false;
      }

      $this->logLastError('user_edit');
      return false;
  }

  /**
   * deletes a user
   *
   * @param array $userID  userID of the user
   * @param boolean $moveToTrash whether to delete user or move to trash
   * @return boolean       true on success, false on failure
   * @author th
   */
  public function user_delete($userID, $moveToTrash = false)
  {
      $userID = MySQL::SQLValue($userID, MySQL::SQLVALUE_NUMBER);
      if ($moveToTrash) {
          $values['trash'] = 1;
          $filter['userID'] = $userID;
          $table = $this->kga['server_prefix']."users";

          $query = MySQL::BuildSQLUpdate($table, $values, $filter);
          return $this->conn->Query($query);
      }

      $query = "DELETE FROM " . $this->kga['server_prefix'] . "users WHERE userID = ".$userID;
      $result = $this->conn->Query($query);

      if ($result === false) {
          $this->logLastError('user_delete');
          return false;
      }

      return true;
  }

  /**
  * Get a preference for a user. If no user ID is given the current user is used.
  *
  * @param string  $key     name of the preference to fetch
  * @param integer $userId  (optional) id of the user to fetch the preference for
  * @return string value of the preference or null if there is no such preference
  * @author sl
  */
  public function user_get_preference($key,$userId=null) {
      if ($userId === null)
        $userId = $this->kga['user']['userID'];

      $table  = $this->kga['server_prefix']."preferences";
      $userId = MySQL::SQLValue($userId,  MySQL::SQLVALUE_NUMBER);
      $key    = MySQL::SQLValue($key);

      $query = "SELECT `value` FROM $table WHERE userID = $userId AND `option` = $key";

      $this->conn->Query($query);

      if ($this->conn->RowCount() == 0)
        return null;

      if ($this->conn->RowCount() == 1) {
        $row = $this->conn->RowArray(0,MYSQL_NUM);
        return $row[0];
      }
  }

  /**
  * Get several preferences for a user. If no user ID is given the current user is used.
  *
  * @param array   $keys    names of the preference to fetch in an array
  * @param integer $userId  (optional) id of the user to fetch the preference for
  * @return array  with keys for every found preference and the found value
  * @author sl
  */
  public function user_get_preferences(array $keys,$userId=null) {
      if ($userId === null)
        $userId = $this->kga['user']['userID'];

      $table  = $this->kga['server_prefix']."preferences";
      $userId = MySQL::SQLValue($userId,  MySQL::SQLVALUE_NUMBER);

      $preparedKeys = array();
      foreach ($keys as $key)
        $preparedKeys[] = MySQL::SQLValue($key);

      $keysString = implode(",",$preparedKeys);

      $query = "SELECT `option`,`value` FROM $table WHERE userID = $userId AND `option` IN ($keysString)";

      $this->conn->Query($query);

      $preferences = array();

      while (!$this->conn->EndOfSeek()) {
        $row = $this->conn->RowArray();
        $preferences[$row['option']] = $row['value'];
      }

      return $preferences;
  }

  /**
  * Get several preferences for a user which have a common prefix. The returned preferences are striped off
  * the prefix.
  * If no user ID is given the current user is used.
  *
  * @param string  $prefix   prefix all preferenc keys to fetch have in common
  * @param integer $userId  (optional) id of the user to fetch the preference for
  * @return array  with keys for every found preference and the found value
  * @author sl
  */
  public function user_get_preferences_by_prefix($prefix,$userId=null) {
      if ($userId === null)
        $userId = $this->kga['user']['userID'];

      $prefixLength = strlen($prefix);

      $table  = $this->kga['server_prefix']."preferences";
      $userId = MySQL::SQLValue($userId,  MySQL::SQLVALUE_NUMBER);
      $prefix = MySQL::SQLValue($prefix.'%');

      $query = "SELECT `option`,`value` FROM $table WHERE userID = $userId AND `option` LIKE $prefix";
      $this->conn->Query($query);

      $preferences = array();

      while (!$this->conn->EndOfSeek()) {
        $row = $this->conn->RowArray();
        $key = substr($row['option'],$prefixLength);
        $preferences[$key] = $row['value'];
      }

      return $preferences;
  }

  /**
  * Save one or more preferences for a user. If no user ID is given the current user is used.
  * The array has to assign every preference key a value to store.
  * Example: array ( 'setting1' => 'value1', 'setting2' => 'value2');
  *
  * A prefix can be specified, which will be prepended to every preference key.
  *
  * @param array   $data   key/value pairs to store
  * @param string  $prefix prefix for all preferences
  * @param integer $userId (optional) id of another user than the current
  * @return boolean        true on success, false on failure
  * @author sl
  */
  public function user_set_preferences(array $data, $prefix='', $userId=null)
  {
      if ($userId === null) {
        $userId = $this->kga['user']['userID'];
      }

      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('user_set_preferences');
        return false;
      }

      $table  = $this->kga['server_prefix']."preferences";

      $filter['userID']  = MySQL::SQLValue($userId,  MySQL::SQLVALUE_NUMBER);
      $values['userID']  = $filter['userID'];
      foreach ($data as $key=>$value) {
        $values['option']   = MySQL::SQLValue($prefix.$key);
        $values['value'] = MySQL::SQLValue($value);
        $filter['option']   = $values['option'];

        $this->conn->AutoInsertUpdate($table, $values, $filter);
      }

      return $this->conn->TransactionEnd();
  }

  /**
  * Assigns a leader to 1-n groups by adding entries to the cross table
  *
  * @param int $userID        userID of the group leader to whom the groups will be assigned
  * @param array $groupIDs    contains one or more groupIDs
  * @return boolean            true on success, false on failure
  * @author ob
  */
  public function assign_groupleaderToGroups($userID, $groupIDs) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_groupleaderToGroups');
        return false;
      }

      $table = $this->kga['server_prefix']."groupleaders";
      $filter['userID'] = MySQL::SQLValue($userID, MySQL::SQLVALUE_NUMBER);
      $query = MySQL::BuildSQLDelete($table, $filter);

      $d_result = $this->conn->Query($query);

      if ($d_result == false) {
          $this->logLastError('assign_groupleaderToGroups');
          $this->conn->TransactionRollback();
          return false;
      }

      foreach ($groupIDs as $groupID) {
        $values['groupID']       = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
        $values['userID']   = MySQL::SQLValue($userID     , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);

        $result = $this->conn->Query($query);

        if ($result == false) {
                $this->logLastError('assign_groupleaderToGroups');
                $this->conn->TransactionRollback();
                return false;
        }
      }

      $this->update_leader_status();

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_groupleaderToGroups');
          return false;
      }
  }

  /**
  * Assigns a group to 1-n group leaders by adding entries to the cross table
  * (counterpart to assign_groupleaderToGroups)
  *
  * @param array $groupID        groupID of the group to which the group leaders will be assigned
  * @param array $leaderIDs    contains one or more userIDs of the leaders)
  * @return boolean            true on success, false on failure
  * @author ob
  */
  public function assign_groupToGroupleaders($groupID, $leaderIDs) {
      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('assign_groupToGroupleaders');
        return false;
      }

      $table = $this->kga['server_prefix']."groupleaders";
      $filter['groupID'] = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $query = MySQL::BuildSQLDelete($table, $filter);

      $d_result = $this->conn->Query($query);

      if ($d_result == false) {
              $this->logLastError('assign_groupToGroupleaders');
              $this->conn->TransactionRollback();
              return false;
      }

      foreach ($leaderIDs as $leaderID) {
        $values['groupID']       = MySQL::SQLValue($groupID      , MySQL::SQLVALUE_NUMBER);
        $values['userID']   = MySQL::SQLValue($leaderID , MySQL::SQLVALUE_NUMBER);
        $query = MySQL::BuildSQLInsert($table, $values);

        $result = $this->conn->Query($query);

        if ($result == false) {
                $this->logLastError('assign_groupToGroupleaders');
                $this->conn->TransactionRollback();
                return false;
        }
      }

      $this->update_leader_status();

      if ($this->conn->TransactionEnd() == true) {
          return true;
      } else {
          $this->logLastError('assign_groupToGroupleaders');
          return false;
      }
  }

  /**
  * returns all the groups of the given group leader
  *
  * @param array $userID  userID of the group leader
  * @return array         contains the groupIDs of the groups or false on error
  * @author th
  */
  public function groupleader_get_groups($userID) {
      $filter['userID'] = MySQL::SQLValue($userID, MySQL::SQLVALUE_NUMBER);
      $columns[]            = "groupID";
      $table = $this->kga['server_prefix']."groupleaders";

      $result = $this->conn->SelectRows($table, $filter, $columns);
      if ($result == false) {
          $this->logLastError('groupleader_get_groups');
          return false;
      }

      $groupIDs = array();
      $counter = 0;

      $rows = $this->conn->RowArray(0,MYSQL_ASSOC);

      if ($this->conn->RowCount()) {
          foreach ($rows as $row) {
              $groupIDs[$counter] = $row['groupID'];
              $counter++;
          }
          return $groupIDs;
      } else {
          $this->logLastError('groupleader_get_groups');
          return false;
      }
  }

  /**
  * returns all the group leaders of the given group
  *
  * @param array $groupID  groupID of the group
  * @return array         contains the userIDs of the group's group leaders or false on error
  * @author th
  */
  public function group_get_groupleaders($groupID) {
      $groupID = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $p = $this->kga['server_prefix'];

      $query = "SELECT userID FROM ${p}groupleaders
      JOIN ${p}users USING(userID)
      WHERE groupID = $groupID AND trash=0;";

      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('group_get_groupleaders');
          return false;
      }

      $leaderIDs = array();
      $counter     = 0;

      $rows = $this->conn->RowArray(0,MYSQL_ASSOC);

      if ($this->conn->RowCount()) {
          $this->conn->MoveFirst();
          while (! $this->conn->EndOfSeek()) {
              $row = $this->conn->Row();
              $leaderIDs[$counter] = $row->userID;
              $counter++;
          }
          return $leaderIDs;
      } else {
          return array();
      }
  }

  /**
  * Adds a new group
  *
  * @param array $data  name and other data of the new group
  * @return int         the groupID of the new group, false on failure
  * @author th
  */
  public function group_create($data) {
      $data = $this->clean_data($data);

      $values ['name']   = MySQL::SQLValue($data ['name'] );
      $table = $this->kga['server_prefix']."groups";
      $result = $this->conn->InsertRow($table, $values);

      if (! $result) {
        $this->logLastError('group_create');
        return false;
      } else {
        return $this->conn->GetLastInsertID();
      }
  }

  /**
  * Returns the data of a certain group
  *
  * @param array $groupID  groupID of the group
  * @return array         the group's data (name, leader ID, etc) as array, false on failure
  * @author th
  */
  public function group_get_data($groupID) {


      $filter['groupID'] = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."groups";
      $result = $this->conn->SelectRows($table, $filter);

      if (! $result) {
        $this->logLastError('group_get_data');
        return false;
      } else {
          return $this->conn->RowArray(0,MYSQL_ASSOC);
      }
  }


  /**
  * Returns the data of a certain status
  *
  * @param array $statusID  ID of the group
  * @return array         	 the group's data (name) as array, false on failure
  * @author mo
  */
  public function status_get_data($statusID) {

      $filter['statusID'] = MySQL::SQLValue($statusID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."statuses";
      $result = $this->conn->SelectRows($table, $filter);

      if (! $result) {
        $this->logLastError('status_get_data');
        return false;
      } else {
          return $this->conn->RowArray(0,MYSQL_ASSOC);
      }
  }

  /**
  * Returns the number of users in a certain group
  *
  * @param array $groupID   groupID of the group
  * @return int            the number of users in the group
  * @author th
  */
  public function group_count_users($groupID) {
      $filter['groupID'] = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."groups_users";
      $result = $this->conn->SelectRows($table, $filter);

      if (! $result) {
        $this->logLastError('group_count_data');
        return false;
      }

      return $this->conn->RowCount()===false?0:$this->conn->RowCount();
  }


  /**
  * Returns the number of time sheet entries with a certain status
  *
  * @param integer $statusID   ID of the status
  * @return int            		the number of timesheet entries with this status
  * @author mo
  */
  public function status_timeSheetEntryCount($statusID) {
      $filter['statusID'] = MySQL::SQLValue($statusID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."timeSheet";
      $result = $this->conn->SelectRows($table, $filter);

      if (! $result) {
        $this->logLastError('status_timeSheetEntryCount');
        return false;
      }

      return $this->conn->RowCount()===false?0:$this->conn->RowCount();
  }


  /**
  * Edits a group by replacing its data by the new array
  *
  * @param array $groupID  groupID of the group to be edited
  * @param array $data    name and other new data of the group
  * @return boolean       true on success, false on failure
  * @author th
  */
  public function group_edit($groupID, $data) {
      $data = $this->clean_data($data);

      $values ['name'] = MySQL::SQLValue($data ['name'] );

      $filter ['groupID']   = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."groups";

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      return $this->conn->Query($query);
  }

 /**
  * Edits a status by replacing its data by the new array
  *
  * @param array $statusID  groupID of the status to be edited
  * @param array $data    name and other new data of the status
  * @return boolean       true on success, false on failure
  * @author mo
  */
  public function status_edit($statusID, $data) {
      $data = $this->clean_data($data);

      $values ['status'] = MySQL::SQLValue($data ['status'] );

      $filter ['statusID']   = MySQL::SQLValue($statusID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."statuses";

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      return $this->conn->Query($query);
  }

  /**
   * Set the groups in which the user is a member in.
   * @param int $userId   id of the user
   * @param array $groups  array of the group ids to be part of
   * @return boolean       true on success, false on failure
   * @author sl
   */
  public function setGroupMemberships($userId,array $groups = null) {
    $table = $this->kga['server_prefix']."groups_users";

    if (! $this->conn->TransactionBegin()) {
      $this->logLastError('setGroupMemberships');
      return false;
    }

    $data ['userID']   = MySQL::SQLValue($userId, MySQL::SQLVALUE_NUMBER);
    $result = $this->conn->DeleteRows($table,$data);

    if (!$result) {
      $this->logLastError('setGroupMemberships');
      if (! $this->conn->TransactionRollback())
        $this->logLastError('setGroupMemberships');
      return false;
    }

    foreach ($groups as $group) {
      $data['groupID'] = MySQL::SQLValue($group, MySQL::SQLVALUE_NUMBER);
      $result = $this->conn->InsertRow($table,$data);
      if ($result === false) {
        $this->logLastError('setGroupMemberships');
        if (! $this->conn->TransactionRollback())
          $this->logLastError('setGroupMemberships');
        return false;
      }
    }

    if (! $this->conn->TransactionEnd()) {
      $this->logLastError('setGroupMemberships');
      return false;
    }
  }

  /**
   * Get the groups in which the user is a member in.
   * @param int $userId   id of the user
   * @return array        list of group ids
   */
  public function getGroupMemberships($userId) {
    $filter['userID'] = MySQL::SQLValue($userId);
    $columns[] = "groupID";
    $table = $this->kga['server_prefix']."groups_users";
    $result = $this->conn->SelectRows($table, $filter, $columns);

    if (!$result) {
        $this->logLastError('getGroupMemberships');
        return null;
    }

    $arr = array();
    if ($this->conn->RowCount()) {
      $this->conn->MoveFirst();
      while (! $this->conn->EndOfSeek()) {
          $row = $this->conn->Row();
          $arr[] = $row->groupID;
      }
    }
    return $arr;
  }

  /**
  * deletes a group
  *
  * @param array $groupID  groupID of the group
  * @return boolean       true on success, false on failure
  * @author th
  */
  public function group_delete($groupID) {
      $values['trash'] = 1;
      $filter['groupID'] = MySQL::SQLValue($groupID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."groups";
      $query = MySQL::BuildSQLUpdate($table, $values, $filter);
      return $this->conn->Query($query);
  }

    /**
  * deletes a status
  *
  * @param array $statusID  statusID of the status
  * @return boolean       	 true on success, false on failure
  * @author mo
  */
  public function status_delete($statusID) {
      $filter['statusID'] = MySQL::SQLValue($statusID, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."statuses";
      $query = MySQL::BuildSQLDelete($table, $filter);
      return $this->conn->Query($query);
  }

  /**
  * Returns all configuration variables
  *
  * @return array       array with the options from the configuration table
  * @author th
  */
  public function configuration_get_data() {
      $table = $this->kga['server_prefix']."configuration";
      $result = $this->conn->SelectRows($table);

      $config_data = array();

      $this->conn->MoveFirst();
      while (! $this->conn->EndOfSeek()) {
          $row = $this->conn->Row();
          $config_data[$row->option] = $row->value;
      }

      return $config_data;
  }

  /**
  * Edits a configuration variables by replacing the data by the new array
  *
  * @param array $data    variables array
  * @return boolean       true on success, false on failure
  * @author ob
  */
  public function configuration_edit($data) {
    $data = $this->clean_data($data);

      $table = $this->kga['server_prefix']."configuration";

      if (! $this->conn->TransactionBegin()) {
        $this->logLastError('configuration_edit');
        return false;
      }

      foreach ($data as $key => $value) {
        $filter['option'] = MySQL::SQLValue($key);
        $values ['value'] = MySQL::SQLValue($value);

        $query = MySQL::BuildSQLUpdate($table, $values, $filter);

        $result = $this->conn->Query($query);

        if ($result === false) {
            $this->logLastError('configuration_edit');
            return false;
        }
      }

      if (! $this->conn->TransactionEnd()) {
        $this->logLastError('configuration_edit');
        return false;
      }

      return true;
  }

  /**
  * Returns a list of IDs of all current recordings.
  *
  * @param integer $user ID of user in table users
  * @return array with all IDs of current recordings. This array will be empty if there are none.
  * @author sl
  */
  public function get_current_recordings($userID) {

      $p = $this->kga['server_prefix'];
      $userID = MySQL::SQLValue($userID, MySQL::SQLVALUE_NUMBER);
      $result = $this->conn->Query("SELECT timeEntryID FROM ${p}timeSheet WHERE userID = $userID AND start > 0 AND end = 0");

      if ($result === false) {
          $this->logLastError('get_current_recordings');
          return array();
      }

      $IDs = array();

      $this->conn->MoveFirst();
      while (! $this->conn->EndOfSeek()) {
          $row = $this->conn->Row();
          $IDs[] = $row->timeEntryID;
      }

      return $IDs;
  }

  /**
  * Returns the data of a certain time record
  *
  * @param array $timeEntryID  timeEntryID of the record
  * @return array         the record's data (time, activity id, project id etc) as array, false on failure
  * @author th
  */
  public function timeSheet_get_data($timeEntryID) {
      $p = $this->kga['server_prefix'];

      $timeEntryID = MySQL::SQLValue($timeEntryID, MySQL::SQLVALUE_NUMBER);
	
		$table = $this->getTimeSheetTable();
		$projectTable = $this->getProjectTable();
		$activityTable = $this->getActivityTable();
		$customerTable = $this->getCustomerTable();
		
      	$select = "SELECT $table.*, $projectTable.name AS projectName, $customerTable.name AS customerName, $activityTable.name AS activityName, $customerTable.customerID AS customerID
      				FROM $table
                	JOIN $projectTable USING(projectID)
                	JOIN $customerTable USING(customerID)
                	JOIN $activityTable USING(activityID)";
		
		
      if ($timeEntryID) {
          $result = $this->conn->Query("$select WHERE timeEntryID = " . $timeEntryID);
      } else {
          $result = $this->conn->Query("$select WHERE userID = ".$this->kga['user']['userID']." ORDER BY timeEntryID DESC LIMIT 1");
      }

      if (! $result) {
        $this->logLastError('timeSheet_get_data');
        return false;
      } else {
          return $this->conn->RowArray(0,MYSQL_ASSOC);
      }
  }

  /**
  * delete time sheet entry
  *
  * @param integer $id -> ID of record
  * @author th
  */
  public function timeEntry_delete($id) {

      $filter["timeEntryID"] = MySQL::SQLValue($id, MySQL::SQLVALUE_NUMBER);
      $table = $this->getTimeSheetTable();
      $query = MySQL::BuildSQLDelete($table, $filter);
      return $this->conn->Query($query);
  }

 /**
  * create time sheet entry
  *
  * @param integer $id    ID of record
  * @param integer $data  array with record data
  * @author th
  */
  public function timeEntry_create($data) {
      $data = $this->clean_data($data);

      $values ['location']     =   MySQL::SQLValue( $data ['location'] );
      $values ['comment']      =   MySQL::SQLValue( $data ['comment'] );
      $values ['description']      =   MySQL::SQLValue( $data ['description'] );
      if ($data ['trackingNumber'] == '')
        $values ['trackingNumber'] = 'NULL';
      else
        $values ['trackingNumber'] =   MySQL::SQLValue( $data ['trackingNumber'] );
      $values ['userID']        =   MySQL::SQLValue( $data ['userID']       , MySQL::SQLVALUE_NUMBER );
      $values ['projectID']        =   MySQL::SQLValue( $data ['projectID']       , MySQL::SQLVALUE_NUMBER );
      $values ['activityID']        =   MySQL::SQLValue( $data ['activityID']       , MySQL::SQLVALUE_NUMBER );
      $values ['commentType'] =   MySQL::SQLValue( $data ['commentType'] , MySQL::SQLVALUE_NUMBER );
      $values ['start']           =   MySQL::SQLValue( $data ['start']           , MySQL::SQLVALUE_NUMBER );
      $values ['end']          =   MySQL::SQLValue( $data ['end']          , MySQL::SQLVALUE_NUMBER );
      $values ['duration']         =   MySQL::SQLValue( $data ['duration']         , MySQL::SQLVALUE_NUMBER );
      $values ['rate']         =   MySQL::SQLValue( $data ['rate']         , MySQL::SQLVALUE_NUMBER );
      $values ['cleared']      =   MySQL::SQLValue( $data ['cleared']?1:0  , MySQL::SQLVALUE_NUMBER );
      $values ['budget']   	   =   MySQL::SQLValue($data ['budget']   	   , MySQL::SQLVALUE_NUMBER );
      $values ['approved'] 	   =   MySQL::SQLValue($data ['approved']      , MySQL::SQLVALUE_NUMBER );
      $values ['statusID']   	   =   MySQL::SQLValue($data ['statusID']   	   , MySQL::SQLVALUE_NUMBER );
      $values ['billable'] 	   =   MySQL::SQLValue($data ['billable'] 	   , MySQL::SQLVALUE_NUMBER );

      $table = $this->getTimeSheetTable();
      $success =  $this->conn->InsertRow($table, $values);
      if ($success)
        return  $this->conn->GetLastInsertID();
      else {
        $this->logLastError('timeEntry_create');
        return false;
      }
  }


  /**
  * edit time sheet entry
  *
  * @param integer $id ID of record
  * @param array $data array with new record data
  * @author th
  */
  public function timeEntry_edit($id, Array $data) {
      $data = $this->clean_data($data);

      $original_array = $this->timeSheet_get_data($id);
      $new_array = array();
      $budgetChange = 0;
      $approvedChange = 0;

      foreach ($original_array as $key => $value) {
          if (isset($data[$key]) == true) {
          	// buget is added to total budget for task. So if we change the budget, we need
          	// to first subtract the previous entry before adding the new one
//          	if($key == 'budget') {
//          		$budgetChange = - $value;
//          	} else if($key == 'approved') {
//          		$approvedChange = - $value;
//          	}
              $new_array[$key] = $data[$key];
          } else {
              $new_array[$key] = $original_array[$key];
          }
      }

      $values ['description']  = MySQL::SQLValue($new_array ['description']    						   );
      $values ['comment']      = MySQL::SQLValue($new_array ['comment']                                );
      $values ['location']     = MySQL::SQLValue($new_array ['location']                               );
      if ($new_array ['trackingNumber'] == '')
        $values ['trackingNumber'] = 'NULL';
      else
        $values ['trackingNumber'] = MySQL::SQLValue($new_array ['trackingNumber']                             );
      $values ['userID']        = MySQL::SQLValue($new_array ['userID']         , MySQL::SQLVALUE_NUMBER );
      $values ['projectID']        = MySQL::SQLValue($new_array ['projectID']         , MySQL::SQLVALUE_NUMBER );
      $values ['activityID']        = MySQL::SQLValue($new_array ['activityID']         , MySQL::SQLVALUE_NUMBER );
      $values ['commentType'] = MySQL::SQLValue($new_array ['commentType']  , MySQL::SQLVALUE_NUMBER );
      $values ['start']           = MySQL::SQLValue($new_array ['start']            , MySQL::SQLVALUE_NUMBER );
      $values ['end']          = MySQL::SQLValue($new_array ['end']           , MySQL::SQLVALUE_NUMBER );
      $values ['duration']         = MySQL::SQLValue($new_array ['duration']          , MySQL::SQLVALUE_NUMBER );
      $values ['rate']         = MySQL::SQLValue($new_array ['rate']          , MySQL::SQLVALUE_NUMBER );
      $values ['cleared']      = MySQL::SQLValue($new_array ['cleared']?1:0   , MySQL::SQLVALUE_NUMBER );
      $values ['budget'] 	   = MySQL::SQLValue($new_array ['budget']     	  , MySQL::SQLVALUE_NUMBER );
      $values ['approved'] 	   = MySQL::SQLValue($new_array ['approved']  	  , MySQL::SQLVALUE_NUMBER );
      $values ['statusID'] 	   = MySQL::SQLValue($new_array ['statusID']		  , MySQL::SQLVALUE_NUMBER );
      $values ['billable'] 	   = MySQL::SQLValue($new_array ['billable']	  , MySQL::SQLVALUE_NUMBER );

      $filter ['timeEntryID']           = MySQL::SQLValue($id, MySQL::SQLVALUE_NUMBER);
      $table = $this->kga['server_prefix']."timeSheet";
      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      $success = true;

      if (! $this->conn->Query($query)) $success = false;

      if ($success) {
          if (! $this->conn->TransactionEnd()) {
            $this->logLastError('timeEntry_edit');
            return false;
          }
      } else {
//      	$budgetChange += $values['budget'];
//      	$approvedChange += $values['approved'];
//      	$this->update_evt_budget($values['projectID'], $values['activityID'], $budgetChange);
//      	$this->update_evt_approved($values['projectID'], $values['activityID'], $budgetChange);
          $this->logLastError('timeEntry_edit');
          if (! $this->conn->TransactionRollback()) {
            $this->logLastError('timeEntry_edit');
            return false;
          }
      }

      return $success;
  }


  /**
  * saves timeframe of user in database (table conf)
  *
  * @param string $timeframeBegin unix seconds
  * @param string $timeframeEnd unix seconds
  * @param string $user ID of user
  *
  * @author th
  */
  public function save_timeframe($timeframeBegin,$timeframeEnd,$user) {
      if ($timeframeBegin == 0 && $timeframeEnd == 0) {
          $mon = date("n"); $day = date("j"); $Y = date("Y");
          $timeframeBegin  = mktime(0,0,0,$mon,$day,$Y);
          $timeframeEnd = mktime(23,59,59,$mon,$day,$Y);
      }

      if ($timeframeEnd == mktime(23,59,59,date('n'),date('j'),date('Y')))
        $timeframeEnd = 0;

      $values['timeframeBegin']  = MySQL::SQLValue($timeframeBegin  , MySQL::SQLVALUE_NUMBER );
      $values['timeframeEnd'] = MySQL::SQLValue($timeframeEnd , MySQL::SQLVALUE_NUMBER );

      $table = $this->kga['server_prefix']."users";
      $filter  ['userID']          =   MySQL::SQLValue($user, MySQL::SQLVALUE_NUMBER);


      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      if (! $this->conn->Query($query)) {
        $this->logLastError('save_timeframe');
        return false;
      }

      return true;
  }

  /**
  * returns list of projects for specific group as array
  *
  * @param integer $user ID of user in database
  * @return array
  * @author th
  */
  public function get_projects(array $groups = null) {
      $p = $this->kga['server_prefix'];

      if ($groups === null) {
        $query = "SELECT project.*, customer.name AS customerName
                  FROM ${p}projects AS project
                  JOIN ${p}customers AS customer USING(customerID)
                  WHERE project.trash=0";
      } else {
        $query = "SELECT DISTINCT project.*, customer.name AS customerName
                  FROM ${p}projects AS project
                  JOIN ${p}customers AS customer USING(customerID)
                  JOIN ${p}groups_projects USING(projectID)
                  WHERE ${p}groups_projects.groupID IN (".implode($groups,',').")
                  AND project.trash=0";
      }

      if ($this->kga['conf']['flip_project_display']) {
        $query .= " ORDER BY project.visible DESC, customerName, name;";
      } else {
        $query .= " ORDER BY project.visible DESC, name, customerName;";
      }

      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('get_projects');
          return false;
      }

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);

      if ($rows) {
          $arr = array();
          $i = 0;
          foreach ($rows as $row) {
              $arr[$i]['projectID']    = $row['projectID'];
              $arr[$i]['customerID']   = $row['customerID'];
              $arr[$i]['name']         = $row['name'];
              $arr[$i]['comment']      = $row['comment'];
              $arr[$i]['visible']      = $row['visible'];
              $arr[$i]['filter']       = $row['filter'];
              $arr[$i]['trash']        = $row['trash'];
              $arr[$i]['budget']       = $row['budget'];
              $arr[$i]['effort']       = $row['effort'];
              $arr[$i]['approved']     = $row['approved'];
              $arr[$i]['internal']     = $row['internal'];
              $arr[$i]['customerName'] = $row['customerName'];
              $i++;
          }
          return $arr;
      }
      return array();
  }

  /**
  * returns list of projects for specific group and specific customer as array
  *
  * @param integer $customerID customer id
  * @param array $groups list of group ids
  * @return array
  * @author ob
  */
  public function get_projects_by_customer($customerID, array $groups = null) {
      $customerID  = MySQL::SQLValue($customerID, MySQL::SQLVALUE_NUMBER);
      $p       = $this->kga['server_prefix'];

      if ($this->kga['conf']['flip_project_display']) {
          $sort = "customerName, name";
      } else {
          $sort = "name, customerName";
      }

      if ($groups === null) {
        $query = "SELECT project.*, customer.name AS customerName
                  FROM ${p}projects AS project
                  JOIN ${p}customers AS customer USING(customerID)
                  WHERE customerID = $customerID
                    AND project.internal=0
                    AND project.trash=0
                  ORDER BY $sort;";
      } else {
        $query = "SELECT DISTINCT project.*, customer.name AS customerName
                  FROM ${p}proejcts AS project
                  JOIN ${p}customers AS customer USING(customerID)
                  JOIN ${p}groups_projects USING(projectID)
                  WHERE ${p}groups_projects.groupID  IN (".implode($groups,',').")
                    AND customerID = $customerID
                    AND project.internal=0
                    AND project.trash=0
                  ORDER BY $sort;";
      }

      $this->conn->Query($query);

      $arr = array();
      $i=0;

      $this->conn->MoveFirst();
      while (! $this->conn->EndOfSeek()) {
          $row = $this->conn->Row();
          $arr[$i]['projectID']    = $row->projectID;
          $arr[$i]['name']         = $row->name;
          $arr[$i]['customerName'] = $row->customerName;
          $arr[$i]['customerID']   = $row->customerID;
          $arr[$i]['visible']      = $row->visible;
          $arr[$i]['budget']       = $row->budget;
          $arr[$i]['effort']       = $row->effort;
          $arr[$i]['approved']     = $row->approved;
          $i++;
      }

      return $arr;
  }

  /**
  *  Creates an array of clauses which can be joined together in the WHERE part
  *  of a sql query. The clauses describe whether a line should be included
  *  depending on the filters set.
  *
  *  This method also makes the values SQL-secure.
  *
  * @param Array list of IDs of users to include
  * @param Array list of IDs of customers to include
  * @param Array list of IDs of projects to include
  * @param Array list of IDs of activities to include
  * @return Array list of where clauses to include in the query
  *
  */
  public function timeSheet_whereClausesFromFilters($users, $customers , $projects , $activities ) {

      if (!is_array($users)) $users = array();
      if (!is_array($customers)) $customers = array();
      if (!is_array($projects)) $projects = array();
      if (!is_array($activities)) $activities = array();

      for ($i = 0;$i<count($users);$i++)
        $users[$i] = MySQL::SQLValue($users[$i], MySQL::SQLVALUE_NUMBER);
      for ($i = 0;$i<count($customers);$i++)
        $customers[$i] = MySQL::SQLValue($customers[$i], MySQL::SQLVALUE_NUMBER);
      for ($i = 0;$i<count($projects);$i++)
        $projects[$i] = MySQL::SQLValue($projects[$i], MySQL::SQLVALUE_NUMBER);
      for ($i = 0;$i<count($activities);$i++)
        $activities[$i] = MySQL::SQLValue($activities[$i], MySQL::SQLVALUE_NUMBER);

      $whereClauses = array();

      if (count($users) > 0) {
        $whereClauses[] = "userID in (".implode(',',$users).")";
      }

      if (count($customers) > 0) {
        $whereClauses[] = "customerID in (".implode(',',$customers).")";
      }

      if (count($projects) > 0) {
        $whereClauses[] = "projectID in (".implode(',',$projects).")";
      }

      if (count($activities) > 0) {
        $whereClauses[] = "activityID in (".implode(',',$activities).")";
      }

      return $whereClauses;
  }

  /**
  * returns timesheet for specific user as multidimensional array
  * @TODO: needs new comments
  * @param integer $user ID of user in table users
  * @param integer $start start of timeframe in unix seconds
  * @param integer $end end of timeframe in unix seconds
  * @param integer $filterCleared where -1 (default) means no filtering, 0 means only not cleared entries, 1 means only cleared entries
  * @param 
  * @return array
  * @author th
  */
  public function get_timeSheet($start, $end, $users = null, $customers = null, $projects = null, $activities = null, $limit = false, $reverse_order = false, $filterCleared = null, $startRows = 0, $limitRows = 0, $countOnly = false) {
      if (!is_numeric($filterCleared)) {
        $filterCleared = $this->kga['conf']['hideClearedEntries']-1; // 0 gets -1 for disabled, 1 gets 0 for only not cleared entries
      }
      
      $start    = MySQL::SQLValue($start    , MySQL::SQLVALUE_NUMBER);
      $end   = MySQL::SQLValue($end   , MySQL::SQLVALUE_NUMBER);
      $filterCleared   = MySQL::SQLValue($filterCleared , MySQL::SQLVALUE_NUMBER);
      $limit = MySQL::SQLValue($limit , MySQL::SQLVALUE_BOOLEAN);
      
      $p     = $this->kga['server_prefix'];

      $whereClauses = $this->timeSheet_whereClausesFromFilters($users, $customers, $projects, $activities);

      if (isset($this->kga['customer']))
        $whereClauses[] = "project.internal = 0";

      if ($start)
        $whereClauses[]="(end > $start || end = 0)";
      if ($end)
        $whereClauses[]="start < $end";
      if ($filterCleared > -1)
        $whereClauses[] = "cleared = $filterCleared";
      
      if ($limit) {
		if(!empty($limitRows))
		{
			$startRows = (int)$startRows;
      	  	$limit = "LIMIT $startRows, $limitRows";
		} 
		else 
		{
			if (isset($this->kga['conf']['rowlimit'])) {
				$limit = "LIMIT " .$this->kga['conf']['rowlimit'];
			} else {
				$limit="LIMIT 100";
			}
		}
      } else {
          $limit="";
      }
      
      
      $select = "SELECT timeSheet.*, status.status, customer.name AS customerName, customer.customerID as customerID, activity.name AS activityName,
                        project.name AS projectName, project.comment AS projectComment, user.name AS userName, user.alias AS userAlias ";
      
      if($countOnly) {
      	$select = "SELECT COUNT(*) AS total";
      	$limit = "";
      }
                       
      $query = "$select
                FROM ${p}timeSheet AS timeSheet
                Join ${p}projects AS project USING (projectID)
                Join ${p}customers AS customer USING (customerID)
                Join ${p}users AS user USING(userID)
                Join ${p}statuses AS status USING(statusID)
                Join ${p}activities AS activity USING(activityID) "
                .(count($whereClauses)>0?" WHERE ":" ").implode(" AND ",$whereClauses).
                ' ORDER BY start '.($reverse_order?'ASC ':'DESC ') . $limit.';';

      $result = $this->conn->Query($query);

      if ($result === false)
        $this->logLastError('get_timeSheet');

      if($countOnly)
      {
      	$this->conn->MoveFirst();
      	$row = $this->conn->Row();
      	return $row->total;
      }

      $i=0;
      $arr=array();

          $this->conn->MoveFirst();
          while (! $this->conn->EndOfSeek()) {
              $row = $this->conn->Row();
              $arr[$i]['timeEntryID']           = $row->timeEntryID;

              // Start time should not be less than the selected start time. This would confuse the user.
              if ($start && $row->start <= $start)  {
                $arr[$i]['start'] = $start;
              } else {
                $arr[$i]['start'] = $row->start;
              }

              // End time should not be less than the selected start time. This would confuse the user.
              if ($end && $row->end >= $end)  {
                $arr[$i]['end'] = $end;
              } else {
                $arr[$i]['end'] = $row->end;
              }

              if ($row->end != 0) {
                // only calculate time after recording is complete
                $arr[$i]['duration']            = $arr[$i]['end'] - $arr[$i]['start'];
                $arr[$i]['formattedDuration']   = Format::formatDuration($arr[$i]['duration']);
                $arr[$i]['wage_decimal']        = $arr[$i]['duration']/3600*$row->rate;
                $arr[$i]['wage']                = sprintf("%01.2f",$arr[$i]['wage_decimal']);
              }
              $arr[$i]['budget']   	        = $row->budget;
              $arr[$i]['approved']          = $row->approved;
              $arr[$i]['rate']              = $row->rate;
              $arr[$i]['projectID']         = $row->projectID;
              $arr[$i]['activityID']        = $row->activityID;
              $arr[$i]['userID']            = $row->userID;
              $arr[$i]['projectID']         = $row->projectID;
              $arr[$i]['customerName']      = $row->customerName;
              $arr[$i]['customerID']        = $row->customerID;
              $arr[$i]['activityName']      = $row->activityName;
              $arr[$i]['projectName']       = $row->projectName;
              $arr[$i]['projectComment']    = $row->projectComment;
              $arr[$i]['location']          = $row->location;
              $arr[$i]['trackingNumber']    = $row->trackingNumber;
              $arr[$i]['statusID']          = $row->statusID;
              $arr[$i]['status']            = $row->status;
              $arr[$i]['billable']          = $row->billable;
              $arr[$i]['description']       = $row->description;
              $arr[$i]['comment']           = $row->comment;
              $arr[$i]['cleared']           = $row->cleared;
              $arr[$i]['commentType']       = $row->commentType;
              $arr[$i]['userAlias']         = $row->userAlias;
              $arr[$i]['userName']          = $row->userName;
              $i++;
          }
          return $arr;
  }

  /**
   * A drop-in function to replace checkuser() and be compatible with none-cookie environments.
   *
   * @author th/kp
   */
  public function checkUserInternal($kimai_user)
  {
    $p = $this->kga['server_prefix'];

	if (strncmp($kimai_user, 'customer_', 9) == 0) {
		$customerName = MySQL::SQLValue(substr($kimai_user,9));
		$query = "SELECT customerID FROM ${p}customers WHERE name = $customerName AND NOT trash = '1';";
		$this->conn->Query($query);
		$row = $this->conn->RowArray(0,MYSQL_ASSOC);

		$customerID   = $row['customerID'];
		if ($customerID < 1) {
			kickUser();
		}
	}
	else
	{
		$query = "SELECT userID, status FROM ${p}users WHERE name = '$kimai_user' AND active = '1' AND NOT trash = '1';";
		$this->conn->Query($query);
		$row = $this->conn->RowArray(0,MYSQL_ASSOC);

		$userID   = $row['userID'];
		$status  = $row['status']; // User Status -> 0=Admin | 1=GroupLeader | 2=User
		$name = $kimai_user;

		if ($userID < 1) {
			kickUser();
		}
	}

	// load configuration and language
	$this->get_global_config();
	if (strncmp($kimai_user, 'customer_', 4) == 0) {
		$this->get_customer_config($customerID);
	} else {
		$this->get_user_config($userID);
	}

	// override default language if user has chosen a language in the prefs
	if ($this->kga['conf']['lang'] != "") {
		$this->kga['language'] = $this->kga['conf']['lang'];
		$this->kga['lang'] = array_replace_recursive($this->kga['lang'],include(WEBROOT.'language/'.$this->kga['language'].'.php'));
	}

	return (isset($this->kga['user'])?$this->kga['user']:null);
  }

  /**
  * write global configuration into $this->kga including defaults for user settings.
  *
  * @param integer $user ID of user in table users
  * @return array $this->kga
  * @author th
  *
  */
  public function get_global_config() {
    // get values from global configuration
    $table = $this->kga['server_prefix']."configuration";
    $this->conn->SelectRows($table);

    $this->conn->MoveFirst();
    while (! $this->conn->EndOfSeek()) {
        $row = $this->conn->Row();
        $this->kga['conf'][$row->option] = $row->value;
    }


    $this->kga['conf']['rowlimit'] = 100;
    $this->kga['conf']['skin'] = 'standard';
    $this->kga['conf']['autoselection'] = 1;
    $this->kga['conf']['quickdelete'] = 0;
    $this->kga['conf']['flip_project_display'] = 0;
    $this->kga['conf']['project_comment_flag'] = 0;
    $this->kga['conf']['showIDs'] = 0;
    $this->kga['conf']['noFading'] = 0;
    $this->kga['conf']['lang'] = '';
    $this->kga['conf']['user_list_hidden'] = 0;
    $this->kga['conf']['hideClearedEntries'] = 0;


    $table = $this->kga['server_prefix']."statuses";
    $this->conn->SelectRows($table);

    $this->conn->MoveFirst();
    while (! $this->conn->EndOfSeek()) {
        $row = $this->conn->Row();
        $this->kga['conf']['status'][$row->statusID] = $row->status;
    }
  }

  /**
   * Returns a username for the given $apikey.
   *
   * @param string $apikey
   * @return string|null
   */
  public function getUserByApiKey($apikey)
  {
    if (!$apikey || strlen(trim($apikey)) == 0) {
        return null;
    }

    $table = $this->kga['server_prefix']."users";
    $filter['apikey'] = MySQL::SQLValue($apikey, MySQL::SQLVALUE_TEXT);
    $filter['trash'] = MySQL::SQLValue(0, MySQL::SQLVALUE_NUMBER);

    // get values from user record
    $columns[] = "userID";
    $columns[] = "name";

    $this->conn->SelectRows($table, $filter, $columns);
    $row = $this->conn->RowArray(0, MYSQL_ASSOC);
    return $row['name'];
  }

  /**
  * write details of a specific user into $this->kga
  *
  * @param integer $user ID of user in table users
  * @return array $this->kga
  * @author th
  *
  */
  public function get_user_config($user) {
    if (!$user) return;

    $table = $this->kga['server_prefix']."users";
    $filter['userID'] = MySQL::SQLValue($user, MySQL::SQLVALUE_NUMBER);

    // get values from user record
    $columns[] = "userID";
    $columns[] = "name";
    $columns[] = "status";
    $columns[] = "trash";
    $columns[] = "active";
    $columns[] = "mail";
    $columns[] = "password";
    $columns[] = "ban";
    $columns[] = "banTime";
    $columns[] = "secure";

    $columns[] = "lastProject";
    $columns[] = "lastActivity";
    $columns[] = "lastRecord";
    $columns[] = "timeframeBegin";
    $columns[] = "timeframeEnd";
    $columns[] = "apikey";

    $this->conn->SelectRows($table, $filter, $columns);
    $rows = $this->conn->RowArray(0,MYSQL_ASSOC);
    foreach($rows as $key => $value) {
        $this->kga['user'][$key] = $value;
    }

    $this->kga['user']['groups'] = $this->getGroupMemberships($user);

    // get values from user configuration (user-preferences)
    unset($columns);
    unset($filter);

    $this->kga['conf'] = array_merge($this->kga['conf'],$this->user_get_preferences_by_prefix('ui.'));
    $userTimezone = $this->user_get_preference('timezone');
    if ($userTimezone != '')
      $this->kga['timezone'] = $userTimezone;
    else
      $this->kga['timezone'] = $this->kga['defaultTimezone'];

    date_default_timezone_set($this->kga['timezone']);
  }

  /**
  * write details of a specific customer into $this->kga
  *
  * @param integer $user ID of user in table users
  * @return array $this->kga
  * @author sl
  *
  */
  public function get_customer_config($user) {
    if (!$user) return;

    $table = $this->kga['server_prefix']."customers";
    $filter['customerID'] = MySQL::SQLValue($user, MySQL::SQLVALUE_NUMBER);

    // get values from user record
    $columns[] = "customerID";
    $columns[] = "name";
    $columns[] = "comment";
    $columns[] = "visible";
    $columns[] = "filter";
    $columns[] = "company";
    $columns[] = "street";
    $columns[] = "zipcode";
    $columns[] = "city";
    $columns[] = "phone";
    $columns[] = "fax";
    $columns[] = "mobile";
    $columns[] = "mail";
    $columns[] = "homepage";
    $columns[] = "trash";
    $columns[] = "password";
    $columns[] = "secure";
    $columns[] = "timezone";

    $this->conn->SelectRows($table, $filter, $columns);
    $rows = $this->conn->RowArray(0,MYSQL_ASSOC);
    foreach($rows as $key => $value) {
        $this->kga['customer'][$key] = $value;
    }

    date_default_timezone_set($this->kga['customer']['timezone']);
  }

  /**
  * checks if a customer with this name exists
  *
  * @param string name
  * @return integer
  * @author sl
  */
  public function is_customer_name($name) {
      $name  = MySQL::SQLValue($name);
      $p     = $this->kga['server_prefix'];

      $query = "SELECT customerID FROM ${p}customers WHERE name = $name";

      $this->conn->Query($query);
      return $this->conn->RowCount() == 1;
  }

  /**
  * returns time summary of current timesheet
  *
  * @param integer $user ID of user in table users
  * @param integer $start start of timeframe in unix seconds
  * @param integer $end end of timeframe in unix seconds
  * @return integer
  * @author th
  */
  public function get_duration($start,$end,$users = null, $customers = null, $projects = null, $activities = null,$filterCleared = null) {
      if (!is_numeric($filterCleared)) {
        $filterCleared = $this->kga['conf']['hideClearedEntries']-1; // 0 gets -1 for disabled, 1 gets 0 for only not cleared entries
      }

      $start    = MySQL::SQLValue($start    , MySQL::SQLVALUE_NUMBER);
      $end   = MySQL::SQLValue($end   , MySQL::SQLVALUE_NUMBER);

      $p     = $this->kga['server_prefix'];

      $whereClauses = $this->timeSheet_whereClausesFromFilters($users,$customers,$projects,$activities);

      if ($start)
        $whereClauses[]="end > $start";
      if ($end)
        $whereClauses[]="start < $end";
      if ($filterCleared > -1)
        $whereClauses[] = "cleared = $filterCleared";

      $query = "SELECT start,end,duration FROM ${p}timeSheet
              Join ${p}projects USING(projectID)
              Join ${p}customers USING(customerID)
              Join ${p}users USING(userID)
              Join ${p}activities USING(activityID) "
              .(count($whereClauses)>0?" WHERE ":" ").implode(" AND ",$whereClauses);
      $this->conn->Query($query);

      $this->conn->MoveFirst();
      $sum = 0;
      $consideredStart = 0; // Consider start of selected range if real start is before
      $consideredEnd = 0; // Consider end of selected range if real end is afterwards
      while (! $this->conn->EndOfSeek()) {
        $row = $this->conn->Row();
        if ($row->start <= $start && $row->end < $end)  {
          $consideredStart  = $start;
          $consideredEnd = $row->end;
        }
        else if ($row->start <= $start && $row->end >= $end)  {
          $consideredStart  = $start;
          $consideredEnd = $end;
        }
        else if ($row->start > $start && $row->end < $end)  {
          $consideredStart  = $row->start;
          $consideredEnd = $row->end;
        }
        else if ($row->start > $start && $row->end >= $end)  {
          $consideredStart  = $row->start;
          $consideredEnd = $end;
        }
        $sum+=(int)($consideredEnd - $consideredStart);
      }
      return $sum;
  }

  /**
  * returns list of customers in a group as array
  *
  * @param integer $group ID of group in table groups or "all" for all groups
  * @return array
  * @author th
  */
  public function get_customers(array $groups = null) {
    $p = $this->kga['server_prefix'];

      if ($groups === null) {
          $query = "SELECT customerID, name, contact, visible
              FROM ${p}customers
              WHERE trash=0
              ORDER BY visible DESC, name;";
      } else {
          $query = "SELECT DISTINCT customerID, name, contact, visible
              FROM ${p}customers
              JOIN ${p}groups_customers AS g_c USING (customerID)
              WHERE g_c.groupID IN (".implode($groups,',').")
                AND trash=0
              ORDER BY visible DESC, name;";
      }

      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('get_customers');
          return false;
      }

      $i = 0;
      if ($this->conn->RowCount()) {
          $arr = array();
          $this->conn->MoveFirst();
          while (! $this->conn->EndOfSeek()) {
              $row = $this->conn->Row();
              $arr[$i]['customerID']       = $row->customerID;
              $arr[$i]['name']     = $row->name;
              $arr[$i]['contact']  = $row->contact;
              $arr[$i]['visible']  = $row->visible;
              $i++;
          }
          return $arr;

      }
      return array();
  }

  ## Load into Array: Activities
  public function get_activities(array $groups = null) {
  $p = $this->kga['server_prefix'];

      if ($groups === null) {
          $query = "SELECT activityID, name, visible, assignable
              FROM ${p}activities
              WHERE trash=0
              ORDER BY visible DESC, name;";
      } else {
          $query = "SELECT DISTINCT activityID, name, visible, assignable
              FROM ${p}activities
              JOIN ${p}groups_activities AS g_a USING(activityID)
              WHERE g_a.groupID IN (".implode($groups,',').")
                AND trash=0
              ORDER BY visible DESC, name;";
      }

      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('get_activities');
          return false;
      }

      $arr = array();
      $i = 0;
      if ($this->conn->RowCount()) {
          $this->conn->MoveFirst();
          while (! $this->conn->EndOfSeek()) {
              $row = $this->conn->Row();
              $arr[$i]['activityID']       = $row->activityID;
              $arr[$i]['name']     = $row->name;
              $arr[$i]['visible']  = $row->visible;
              $arr[$i]['assignable']  = $row->assignable;
              $i++;
          }
          return $arr;
      } else {
          return array();
      }
  }

  /**
  * Get an array of activities, which should be displayed for a specific project.
  * Those are activities which were assigned to the project or which are assigned to
  * no project.
  *
  * Two joins can occur:
  *  The JOIN is for filtering the activities by groups.
  *
  *  The LEFT JOIN gives each activity row the project id which it has been assigned
  *  to via the projects_activities table or NULL when there is no assignment. So we only
  *  take rows which have NULL or the project id in that column.
  *
  *  @author sl
  */
  public function get_activities_by_project($projectID, array $groups = null) {
      $projectID = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER);

      $p = $this->kga['server_prefix'];

      if ($groups === null) {
          $query = "SELECT activity.*, p_a.budget, p_a.approved, p_a.effort
            FROM ${p}activities AS activity
            LEFT JOIN ${p}projects_activities AS p_a USING(activityID)
            WHERE activity.trash=0
              AND (projectID = $projectID OR projectID IS NULL)
            ORDER BY visible DESC, name;";
      } else {
          $query = "SELECT DISTINCT activity.*, p_a.budget, p_a.approved, p_a.effort
            FROM ${p}activities AS activity
            JOIN ${p}groups_activities USING(activityID)
            LEFT JOIN ${p}projects_activities p_a USING(activityID)
            WHERE `${p}groups_activities`.`groupID`  IN (".implode($groups,',').")
              AND activity.trash=0
              AND (projectID = $projectID OR projectID IS NULL)
            ORDER BY visible DESC, name;";
      }

      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('get_activities_by_project');
          return false;
      }

      $arr = array();
      if ($this->conn->RowCount()) {
          $this->conn->MoveFirst();
          while (! $this->conn->EndOfSeek()) {
              $row = $this->conn->Row();
              $arr[$row->activityID]['activityID']       = $row->activityID;
              $arr[$row->activityID]['name']     = $row->name;
              $arr[$row->activityID]['visible']  = $row->visible;
              $arr[$row->activityID]['budget']   = $row->budget;
              $arr[$row->activityID]['approved'] = $row->approved;
              $arr[$row->activityID]['effort']   = $row->effort;
          }
          return $arr;
      } else {
          return array();
      }
  }

  /**
  * returns list of activities used with specified customer
  *
  * @param integer $customer filter for only this ID of a customer
  * @return array
  * @author sl
  */
  public function get_activities_by_customer($customer_ID) {
      $p = $this->kga['server_prefix'];

      $customer_ID = MySQL::SQLValue($customer_ID , MySQL::SQLVALUE_NUMBER);

      $query = "SELECT DISTINCT activityID, name, visible
          FROM ${p}activities
          WHERE activityID IN
              (SELECT activityID FROM ${p}timeSheet
                WHERE projectID IN (SELECT projectID FROM ${p}projects WHERE customerID = $customer_ID))
            AND trash=0";

      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('get_activities_by_customer');
          return false;
      }

      $arr = array();
      $i = 0;

      if ($this->conn->RowCount()) {
          $this->conn->MoveFirst();
          while (! $this->conn->EndOfSeek()) {
              $row = $this->conn->Row();
              $arr[$i]['activityID']       = $row->activityID;
              $arr[$i]['name']     = $row->name;
              $arr[$i]['visible']  = $row->visible;
              $i++;
          }
          return $arr;
      } else {
          return array();
      }
  }

  /**
  * returns time of currently running activity recording as array
  *
  * result is meant as params for the stopwatch if the window is reloaded
  *
  * <pre>
  * returns:
  * [all] start time of entry in unix seconds (forgot why I named it this way, sorry ...)
  * [hour]
  * [min]
  * [sec]
  * </pre>
  *
  * @param integer $user ID of user in table users
  * @return array
  * @author th
  */
  public function get_current_timer() {
      $user  = MySQL::SQLValue($this->kga['user']['userID'] , MySQL::SQLVALUE_NUMBER);
    $p     = $this->kga['server_prefix'];

      $this->conn->Query("SELECT timeEntryID, start FROM ${p}timeSheet WHERE userID = $user AND end = 0;");

      if ($this->conn->RowCount() == 0) {
          $current_timer['all']  = 0;
          $current_timer['hour'] = 0;
          $current_timer['min']  = 0;
          $current_timer['sec']  = 0;
      }
      else {

        $row = $this->conn->RowArray(0,MYSQL_ASSOC);

        $start    = (int)$row['start'];

        $aktuelleMessung = Format::hourminsec(time()-$start);
        $current_timer['all']  = $start;
        $current_timer['hour'] = $aktuelleMessung['h'];
        $current_timer['min']  = $aktuelleMessung['i'];
        $current_timer['sec']  = $aktuelleMessung['s'];
      }
      return $current_timer;
  }

  /**
  * returns the version of the installed Kimai database to compare it with the package version
  *
  * @return array
  * @author th
  *
  * [0] => version number (x.x.x)
  * [1] => svn revision number
  *
  */
  public function get_DBversion() {
      $filter['option'] = MySQL::SQLValue('version');
      $columns[] = "value";
      $table = $this->kga['server_prefix']."configuration";
      $result = $this->conn->SelectRows($table, $filter, $columns);

      if ($result == false) {
        // before database revision 1369 (503 + 866)
        $table = $this->kga['server_prefix']."var";
        unset($filter);
        $filter['var'] = MySQL::SQLValue('version');
        $result = $this->conn->SelectRows($table, $filter, $columns);
      }

      $row = $this->conn->RowArray(0,MYSQL_ASSOC);
      $return[] = $row['value'];

      if ($result == false) $return[0] = "0.5.1";

      $filter['option'] = MySQL::SQLValue('revision');
      $result = $this->conn->SelectRows($table, $filter, $columns);

      if ($result == false) {
        // before database revision 1369 (503 + 866)
        unset($filter);
        $filter['var'] = MySQL::SQLValue('revision');
        $result = $this->conn->SelectRows($table, $filter, $columns);
      }

      $row = $this->conn->RowArray(0,MYSQL_ASSOC);
      $return[] = $row['value'];

      return $return;
  }

  /**
  * returns the key for the session of a specific user
  *
  * the key is both stored in the database (users table) and a cookie on the client.
  * when the keys match the user is allowed to access the Kimai GUI.
  * match test is performed via public function userCheck()
  *
  * @param integer $user ID of user in table users
  * @return string
  * @author th
  */
  public function get_seq($user) {
      if (strncmp($user, 'customer_', 9) == 0) {
        $filter['name'] = MySQL::SQLValue(substr($user,9));
        $table = $this->getCustomerTable();
      }
      else {
        $filter['name'] = MySQL::SQLValue($user);
        $table = $this->getUserTable();
      }

      $columns[] = "secure";

      $result = $this->conn->SelectRows($table, $filter, $columns);
      if ($result == false) {
          $this->logLastError('get_seq');
          return false;
      }

      $row = $this->conn->RowArray(0,MYSQL_ASSOC);
      return $row['secure'];
  }

  /**
   * return status names
   * @param integer $statusIds
   */
  public function get_status($statusIds) {
      $p = $this->kga['server_prefix'];
      $statusIds = implode(',', $statusIds);
      $query = "SELECT status FROM ${p}statuses where statusID in ( $statusIds ) order by statusID";
      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('get_status');
          return false;
      }

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);
      foreach($rows as $row) {
      	$res[] = $row['status'];
      }
      return $res;
  }
      
  /**
   * returns array of all status with the status id as key
   *
   * @return array
   * @author mo
   */
  public function get_statuses() {
      $p = $this->kga['server_prefix'];

        $query = "SELECT * FROM ${p}statuses
        ORDER BY status;";
      $this->conn->Query($query);

      $arr = array();
      $i=0;

      $this->conn->MoveFirst();
      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);

      if ($rows === false) {
          return array();
      }

      foreach($rows as $row) {
          $arr[] = $row;
          $arr[$i]['timeSheetEntryCount'] = $this->status_timeSheetEntryCount($row['statusID']);
          $i++;
      }

      return $arr;
  }

  /**
   * add a new status
   * @param Array $statusArray
   */
	public function status_create($status) {
      	$values['status'] = MySQL::SQLValue(trim($status['status']));
		
      	$table = $this->kga['server_prefix']."statuses";
      	$result = $this->conn->InsertRow($table, $values);
      	if (! $result) {
        	$this->logLastError('add_status');
        	return false;
      	}
		return true;
  }

  /**
  * returns array of all users
  *
  * [userID] => 23103741
  * [name] => admin
  * [status] => 0
  * [mail] => 0
  * [active] => 0
  *
  *
  * @param array $groups list of group ids the users must be a member of
  * @return array
  * @author th
  */
  public function get_users($trash=0,array $groups = null) {
      $p = $this->kga['server_prefix'];


      $trash = MySQL::SQLValue($trash, MySQL::SQLVALUE_NUMBER );

      if ($groups === null)
        $query = "SELECT * FROM ${p}users
        WHERE trash = $trash
        ORDER BY name ;";
      else
        $query = "SELECT * FROM ${p}users
         JOIN ${p}groups_users AS g_u USING(userID)
        WHERE g_u.groupID IN (".implode($groups,',').") AND
         trash = $trash
        ORDER BY name ;";
      $this->conn->Query($query);

      $rows = $this->conn->RowArray(0,MYSQL_ASSOC);

      $i=0;
      $arr = array();

      $this->conn->MoveFirst();
      while (! $this->conn->EndOfSeek()) {
          $row = $this->conn->Row();
          $arr[$i]['userID']     = $row->userID;
          $arr[$i]['name']   = $row->name;
          $arr[$i]['status']    = $row->status;
          $arr[$i]['mail']   = $row->mail;
          $arr[$i]['active'] = $row->active;
          $arr[$i]['trash']  = $row->trash;

          if ($row->password !='' && $row->password != '0') {
              $arr[$i]['passwordSet'] = "yes";
          } else {
              $arr[$i]['passwordSet'] = "no";
          }
          $i++;
      }

      return $arr;
  }

  /**
  * returns array of all groups
  *
  * [0]=> array(6) {
  *      ["groupID"]      =>  string(1) "1"
  *      ["groupName"]    =>  string(5) "admin"
  *      ["userID"]  =>  string(9) "1234"
  *      ["trash"]   =>  string(1) "0"
  *      ["count_users"] =>  string(1) "2"
  *      ["leader_name"] =>  string(5) "user1"
  * }
  *
  * [1]=> array(6) {
  *      ["groupID"]      =>  string(1) "2"
  *      ["groupName"]    =>  string(4) "Test"
  *      ["userID"]  =>  string(9) "12345"
  *      ["trash"]   =>  string(1) "0"
  *      ["count_users"] =>  string(1) "1"
  *      ["leader_name"] =>  string(7) "user2"
  *  }
  *
  * @return array
  * @author th
  *
  */
  public function get_groups($trash=0) {
      $p = $this->kga['server_prefix'];

      // Lock tables for alles queries executed until the end of this public function
      $lock  = "LOCK TABLE ${p}users READ, ${p}groups READ, ${p}groupleaders READ, ${p}groups_users READ;";
      $result = $this->conn->Query($lock);
      if (!$result) {
        $this->logLastError('get_groups');
        return false;
      }

  //------

      if (!$trash) {
          $trashoption = "WHERE ${p}groups.trash !=1";
      }

      $query  = "SELECT * FROM ${p}groups $trashoption ORDER BY name;";
      $this->conn->Query($query);

      // rows into array
      $groups = array();
      $i=0;

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);

      foreach ($rows as $row){
          $groups[] = $row;

          // append user count
          $groups[$i]['count_users'] = $this->group_count_users($row['groupID']);

          // append leader array
          $userID_array = $this->group_get_groupleaders($row['groupID']);
          $leaderNames = array();
          $j = 0;
          foreach ($userID_array as $userID) {
              $leaderNames[$j] = $this->userIDToName($userID);
              $j++;
          }

          $groups[$i]['leader_name'] = $leaderNames;

          $i++;
      }

  //------

      // Unlock tables
      $unlock = "UNLOCK TABLES;";
      $result = $this->conn->Query($unlock);
      if (!$result) {
        $this->logLastError('get_groups');
        return false;
      }

      return $groups;
  }

  /**
  * returns array of all groups of a group leader
  *
  * [0]=> array(6) {
  *      ["groupID"]      =>  string(1) "1"
  *      ["groupName"]    =>  string(5) "admin"
  *      ["userID"]  =>  string(9) "1234"
  *      ["trash"]   =>  string(1) "0"
  *      ["count_users"] =>  string(1) "2"
  *      ["leader_name"] =>  string(5) "user1"
  * }
  *
  * [1]=> array(6) {
  *      ["groupID"]      =>  string(1) "2"
  *      ["groupName"]    =>  string(4) "Test"
  *      ["userID"]  =>  string(9) "12345"
  *      ["trash"]   =>  string(1) "0"
  *      ["count_users"] =>  string(1) "1"
  *      ["leader_name"] =>  string(7) "user2"
  *  }
  *
  * @return array
  * @author sl
  *
  */
  public function get_groups_by_leader($leader_id,$trash=0) {
      $leader_id = MySQL::SQLValue($leader_id, MySQL::SQLVALUE_NUMBER  );

      $p = $this->kga['server_prefix'];

      // Lock tables for alles queries executed until the end of this public function
      $lock  = "LOCK TABLE ${p}users READ, ${p}groups READ, ${p}groupleaders READ;";
      $result = $this->conn->Query($lock);
      if (!$result) {
        $this->logLastError('get_groups_by_leader');
        return false;
      }

  //------

      if (!$trash) {
          $trashoption = "AND group.trash !=1";
      }
      $query = "SELECT group.*
      FROM ${p}groups AS group
      JOIN ${p}groupleaders USING(groupID)
      WHERE userID = $leader_id $trashoption ORDER BY group.name";
      $result = $this->conn->Query($query);
      if (!$result) {
        $this->logLastError('get_groups_by_leader');
        return false;
      }

      // rows into array
      $groups = array();
      $i=0;

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);

      foreach ($rows as $row){
          $groups[] = $row;

          // append user count
          $groups[$i]['count_users'] = $this->group_count_users($row['groupID']);

          // append leader array
          $userID_array = $this->group_get_groupleaders($row['groupID']);
          $leaderNames = array();
          $j = 0;
          foreach ($userID_array as $userID) {
              $leaderNames[$j] = $this->userIDToName($userID);
              $j++;
          }

          $groups[$i]['leader_name'] = $leaderNames;

          $i++;
      }

  //------

      // Unlock tables
      $unlock = "UNLOCK TABLES;";
      $result = $this->conn->Query($unlock);
      if (!$result) {
        $this->logLastError('get_groups_by_leader');
        return false;
      }

      return $groups;
  }

  /**
  * Performed when the stop buzzer is hit.
  *
  * @param integer $id id of the entry to stop
  * @author th, sl
  * @return boolean
  */
  public function stopRecorder($id) {
  ## stop running recording |
      $table = $this->kga['server_prefix']."timeSheet";

      $task = $this->timeSheet_get_data($id);

      $filter['timeEntryID'] = $task['timeEntryID'];
      $filter['end'] = 0; // only update running tasks

      $rounded = Rounding::roundTimespan($task['start'],time(),$this->kga['conf']['roundPrecision']);

      $values['start'] = $rounded['start'];
      $values['end']  = $rounded['end'];
      $values['duration'] = $values['end']-$values['start'];


      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      return $this->conn->Query($query);
  }

  /**
  * starts timesheet record
  *
  * @param integer $projectID ID of project to record
  * @author th, sl
  * @return id of the new entry or false on failure
  */
  public function startRecorder($projectID,$activityID,$user) {
      $projectID = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER  );
      $activityID = MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER  );
      $user   = MySQL::SQLValue($user  , MySQL::SQLVALUE_NUMBER  );


      $values ['projectID'] = $projectID;
      $values ['activityID'] = $activityID;
      $values ['start']    = time();
      $values ['userID'] = $user;
      $values ['statusID'] = 1;
      $rate = $this->get_best_fitting_rate($user,$projectID,$activityID);
      if ($rate)
        $values ['rate'] = $rate;

      $table = $this->kga['server_prefix']."timeSheet";
      $result = $this->conn->InsertRow($table, $values);

      if (! $result) {
        $this->logLastError('startRecorder');
        return false;
      }

      return $this->conn->GetLastInsertID();
  }

  /**
  * Just edit the project for an entry. This is used for changing the project
  * of a running entry.
  *
  * @param $timeEntryID id of the timesheet entry
  * @param $projectID id of the project to change to
  */
  public function timeEntry_edit_project($timeEntryID,$projectID) {
      $timeEntryID = MySQL::SQLValue($timeEntryID, MySQL::SQLVALUE_NUMBER  );
      $projectID = MySQL::SQLValue($projectID, MySQL::SQLVALUE_NUMBER );

      $table = $this->kga['server_prefix']."timeSheet";

      $filter['timeEntryID'] = $timeEntryID;

      $values['projectID'] = $projectID;

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      return $this->conn->Query($query);
  }

  /**
  * Just edit the task for an entry. This is used for changing the task
  * of a running entry.
  *
  * @param $timeEntryID id of the timesheet entry
  * @param $activityID id of the task to change to
  */
  public function timeEntry_edit_activity($timeEntryID,$activityID) {
      $timeEntryID = MySQL::SQLValue($timeEntryID, MySQL::SQLVALUE_NUMBER  );
      $activityID = MySQL::SQLValue($activityID, MySQL::SQLVALUE_NUMBER );

      $table = $this->kga['server_prefix']."timeSheet";

      $filter['timeEntryID'] = $timeEntryID;

      $values['activityID'] = $activityID;

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      return $this->conn->Query($query);
  }

  /**
  * return ID of specific user named 'XXX'
  *
  * @param integer $name name of user in table users
  * @return id of the customer
  */
  public function customer_nameToID($name) {
      return $this->name2id($this->kga['server_prefix']."customers",'customerID','name',$name);
  }

  /**
  * return ID of specific user named 'XXX'
  *
  * @param integer $name name of user in table users
  * @return string
  * @author th
  */
  public function user_name2id($name) {
      return $this->name2id($this->kga['server_prefix']."users",'userID','name',$name);
  }

  /**
   * Query a table for an id by giving the name of an entry.
   * @author sl
   */
  private function name2id($table,$endColumn,$filterColumn,$value) {
      $filter [$filterColumn] = MySQL::SQLValue($value);
      $columns[] = $endColumn;

      $result = $this->conn->SelectRows($table, $filter, $columns);
      if ($result == false) {
          $this->logLastError('name2id');
          return false;
      }

      $row = $this->conn->RowArray(0,MYSQL_ASSOC);

      if ($row === false)
        return false;

      return $row[$endColumn];
  }

  /**
  * return name of a user with specific ID
  *
  * @param string $id the user's userID
  * @return int
  * @author th
  */
  public function userIDToName($id) {
      $filter ['userID'] = MySQL::SQLValue($id, MySQL::SQLVALUE_NUMBER);
      $columns[] = "name";
      $table = $this->kga['server_prefix']."users";

      $result = $this->conn->SelectRows($table, $filter, $columns);
      if ($result == false) {
          $this->logLastError('userIDToName');
          return false;
      }

      $row = $this->conn->RowArray(0,MYSQL_ASSOC);
      return $row['name'];
  }

  /**
  * returns the date of the first timerecord of a user (when did the user join?)
  * this is needed for the datepicker
  * @param integer $id of user
  * @return integer unix seconds of first timesheet record
  * @author th
  */
  public function getjointime($userID) {
      $userID = MySQL::SQLValue($userID, MySQL::SQLVALUE_NUMBER);
      $p = $this->kga['server_prefix'];

      $query = "SELECT start FROM ${p}timeSheet WHERE userID = $userID ORDER BY start ASC LIMIT 1;";

      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('getjointime');
          return false;
      }

      $result_array = $this->conn->RowArray(0,MYSQL_NUM);

      if ($result_array[0] == 0) {
          return mktime(0,0,0,date("n"),date("j"),date("Y"));
      } else {
          return $result_array[0];
      }
  }

  /**
  * returns list of users the given user can watch
  *
  * @param integer $user ID of user in table users
  * @return array
  * @author sl
  */
  public function get_watchable_users($user) {
      $arr = array();
      $userID = MySQL::SQLValue($user['userID'], MySQL::SQLVALUE_NUMBER);

      if ($user['status'] == "0") { // if is admin
        $query = "SELECT * FROM " . $this->kga['server_prefix'] . "users WHERE trash=0 ORDER BY name";
        $result = $this->conn->Query($query);
        return $this->conn->RecordsArray(MYSQL_ASSOC);
      }

      // get groups the user is a leader of

      $query = "SELECT groupID FROM " . $this->kga['server_prefix'] . "groupleaders WHERE userID=$userID";
      $success = $this->conn->Query($query);

      if (!$success) {
        $this->logLastError('get_watchable_users');
        return array();
      }

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);
      $leadingGroups = array();
      foreach ($rows as $row) {
        $leadingGroups[] = $row['groupID'];
      }

      return $this->get_users(0,$leadingGroups);

  }

  /**
  * returns assoc. array where the index is the ID of a user and the value the time
  * this user has accumulated in the given time with respect to the filtersettings
  *
  * @param integer $start from this timestamp
  * @param integer $end to this  timestamp
  * @param integer $user ID of user in table users
  * @param integer $customer ID of customer in table customers
  * @param integer $project ID of project in table projects
  * @return array
  * @author sl
  */
  public function get_time_users($start,$end,$users = null, $customers = null, $projects = null, $activities = null) {
      $start    = MySQL::SQLValue($start    , MySQL::SQLVALUE_NUMBER);
      $end   = MySQL::SQLValue($end   , MySQL::SQLVALUE_NUMBER);

      $p     = $this->kga['server_prefix'];

      $whereClauses = $this->timeSheet_whereClausesFromFilters($users,$customers,$projects,$activities);
      $whereClauses[] = "${p}users.trash=0";

      if ($start)
        $whereClauses[]="end > $start";
      if ($end)
        $whereClauses[]="start < $end";

      $query = "SELECT start,end, userID, (end - start) / 3600 * rate AS costs
              FROM ${p}timeSheet
              Join ${p}projects USING(projectID)
              Join ${p}customers USING(customerID)
              Join ${p}users USING(userID)
              Join ${p}activities USING(activityID) "
              .(count($whereClauses)>0?" WHERE ":" ").implode(" AND ",$whereClauses). " ORDER BY start DESC;";
      $result = $this->conn->Query($query);

      if (! $result) {
        $this->logLastError('get_time_users');
        return array();
      }

      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);
      if (!$rows) return array();

      $arr = array();
      $consideredStart = 0;
      $consideredEnd = 0;
      foreach($rows as $row) {
        if ($row['start'] <= $start && $row['end'] < $end)  {
          $consideredStart  = $start;
          $consideredEnd = $row['end'];
        }
        else if ($row['start'] <= $start && $row['end'] >= $end)  {
          $consideredStart  = $start;
          $consideredEnd = $end;
        }
        else if ($row['start'] > $start && $row['end'] < $end)  {
          $consideredStart  = $row['start'];
          $consideredEnd = $row['end'];
        }
        else if ($row['start'] > $start && $row['end'] >= $end)  {
          $consideredStart  = $row['start'];
          $consideredEnd = $end;
        }

        if (isset($arr[$row['userID']])) {
          $arr[$row['userID']]['time']  += (int)($consideredEnd - $consideredStart);
          $arr[$row['userID']]['costs'] += (double)$row['costs'];
        }
        else  {
          $arr[$row['userID']]['time']  = (int)($consideredEnd - $consideredStart);
          $arr[$row['userID']]['costs'] = (double)$row['costs'];
        }
      }

      return $arr;
  }

  /**
  * returns list of time summary attached to customer ID's within specific timeframe as array
  *
  * @param integer $start start of timeframe in unix seconds
  * @param integer $end end of timeframe in unix seconds
  * @param integer $user filter for only this ID of auser
  * @param integer $customer filter for only this ID of a customer
  * @param integer $project filter for only this ID of a project
  * @return array
  * @author sl
  */
  public function get_time_customers($start,$end,$users = null, $customers = null, $projects = null, $activities = null) {
      $start    = MySQL::SQLValue($start    , MySQL::SQLVALUE_NUMBER);
      $end   = MySQL::SQLValue($end   , MySQL::SQLVALUE_NUMBER);

      $p     = $this->kga['server_prefix'];

      $whereClauses = $this->timeSheet_whereClausesFromFilters($users,$customers,$projects,$activities);
      $whereClauses[] = "${p}customers.trash=0";

      if ($start)
        $whereClauses[]="end > $start";
      if ($end)
        $whereClauses[]="start < $end";


      $query = "SELECT start,end, customerID, (end - start) / 3600 * rate AS costs
              FROM ${p}timeSheet
              Left Join ${p}projects USING(projectID)
              Left Join ${p}customers USING(customerID) ".
              (count($whereClauses)>0?" WHERE ":" ").implode(" AND ",$whereClauses);

      $result = $this->conn->Query($query);
      if (! $result) {
        $this->logLastError('get_time_customers');
        return array();
      }
      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);
      if (!$rows) return array();

      $arr = array();
      $consideredStart = 0;
      $consideredEnd = 0;
      foreach ($rows as $row) {
        if ($row['start'] <= $start && $row['end'] < $end)  {
          $consideredStart  = $start;
          $consideredEnd = $row['end'];
        }
        else if ($row['start'] <= $start && $row['end'] >= $end)  {
          $consideredStart  = $start;
          $consideredEnd = $end;
        }
        else if ($row['start'] > $start && $row['end'] < $end)  {
          $consideredStart  = $row['start'];
          $consideredEnd = $row['end'];
        }
        else if ($row['start'] > $start && $row['end'] >= $end)  {
          $consideredStart  = $row['start'];
          $consideredEnd = $end;
        }

        if (isset($arr[$row['customerID']])) {
          $arr[$row['customerID']]['time']  += (int)($consideredEnd - $consideredStart);
          $arr[$row['customerID']]['costs'] += (double)$row['costs'];
        }
        else {
          $arr[$row['customerID']]['time']  = (int)($consideredEnd - $consideredStart);
          $arr[$row['customerID']]['costs'] = (double)$row['costs'];
        }
      }

      return $arr;
  }

  /**
  * returns list of time summary attached to project ID's within specific timeframe as array
  *
  * @param integer $start start time in unix seconds
  * @param integer $end end time in unix seconds
  * @param integer $user filter for only this ID of auser
  * @param integer $customer filter for only this ID of a customer
  * @param integer $project filter for only this ID of a project
  * @return array
  * @author sl
  */
  public function get_time_projects($start,$end,$users = null, $customers = null, $projects = null,$activities = null) {
      $start    = MySQL::SQLValue($start    , MySQL::SQLVALUE_NUMBER);
      $end   = MySQL::SQLValue($end   , MySQL::SQLVALUE_NUMBER);

      $p     = $this->kga['server_prefix'];

      $whereClauses = $this->timeSheet_whereClausesFromFilters($users,$customers,$projects,$activities);
      $whereClauses[] = "${p}projects.trash=0";

      if ($start)
        $whereClauses[]="end > $start";
      if ($end)
        $whereClauses[]="start < $end";

      $query = "SELECT start, end ,projectID, (end - start) / 3600 * rate AS costs
          FROM ${p}timeSheet
          Left Join ${p}projects USING(projectID)
          Left Join ${p}customers USING(customerID) ".
          (count($whereClauses)>0?" WHERE ":" ").implode(" AND ",$whereClauses);

      $result = $this->conn->Query($query);
      if (! $result) {
        $this->logLastError('get_time_projects');
        return array();
      }
      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);
      if (!$rows) return array();

      $arr = array();
      $consideredStart = 0;
      $consideredEnd = 0;
      foreach ($rows as $row) {
        if ($row['start'] <= $start && $row['end'] < $end)  {
          $consideredStart  = $start;
          $consideredEnd = $row['end'];
        }
        else if ($row['start'] <= $start && $row['end'] >= $end)  {
          $consideredStart  = $start;
          $consideredEnd = $end;
        }
        else if ($row['start'] > $start && $row['end'] < $end)  {
          $consideredStart  = $row['start'];
          $consideredEnd = $row['end'];
        }
        else if ($row['start'] > $start && $row['end'] >= $end)  {
          $consideredStart  = $row['start'];
          $consideredEnd = $end;
        }

        if (isset($arr[$row['projectID']])) {
          $arr[$row['projectID']]['time']  += (int)($consideredEnd - $consideredStart);
          $arr[$row['projectID']]['costs'] += (double)$row['costs'];
        }
        else {
          $arr[$row['projectID']]['time']  = (int)($consideredEnd - $consideredStart);
          $arr[$row['projectID']]['costs'] = (double)$row['costs'];
        }
      }
      return $arr;
  }

  /**
  * returns list of time summary attached to activity ID's within specific timeframe as array
  *
  * @param integer $start start time in unix seconds
  * @param integer $end end time in unix seconds
  * @param integer $user filter for only this ID of auser
  * @param integer $customer filter for only this ID of a customer
  * @param integer $project filter for only this ID of a project
  * @return array
  * @author sl
  */
  public function get_time_activities($start,$end,$users = null, $customers = null, $projects = null, $activities = null) {
      $start    = MySQL::SQLValue($start    , MySQL::SQLVALUE_NUMBER);
      $end   = MySQL::SQLValue($end   , MySQL::SQLVALUE_NUMBER);

      $p     = $this->kga['server_prefix'];

      $whereClauses = $this->timeSheet_whereClausesFromFilters($users,$customers,$projects,$activities);
      $whereClauses[] = "${p}activities.trash = 0";

      if ($start)
        $whereClauses[]="end > $start";
      if ($end)
        $whereClauses[]="start < $end";

      $query = "SELECT start, end, activityID, (end - start) / 3600 * rate AS costs
          FROM ${p}timeSheet
          Left Join ${p}activities USING(activityID)
          Left Join ${p}projects USING(projectID)
          Left Join ${p}customers USING(customerID) ".
          (count($whereClauses)>0?" WHERE ":" ").implode(" AND ",$whereClauses);

      $result = $this->conn->Query($query);
      if (! $result) {
        $this->logLastError('get_time_activities');
        return array();
      }
      $rows = $this->conn->RecordsArray(MYSQL_ASSOC);
      if (!$rows) return array();

      $arr = array();
      $consideredStart = 0;
      $consideredEnd = 0;
      foreach ($rows as $row) {
        if ($row['start'] <= $start && $row['end'] < $end)  {
          $consideredStart  = $start;
          $consideredEnd = $row['end'];
        }
        else if ($row['start'] <= $start && $row['end'] >= $end)  {
          $consideredStart  = $start;
          $consideredEnd = $end;
        }
        else if ($row['start'] > $start && $row['end'] < $end)  {
          $consideredStart  = $row['start'];
          $consideredEnd = $row['end'];
        }
        else if ($row['start'] > $start && $row['end'] >= $end)  {
          $consideredStart  = $row['start'];
          $consideredEnd = $end;
        }

        if (isset($arr[$row['activityID']])) {
          $arr[$row['activityID']]['time']  += (int)($consideredEnd - $consideredStart);
          $arr[$row['activityID']]['costs'] += (double)$row['costs'];
        }
        else {
          $arr[$row['activityID']]['time'] = (int)($end - $start);
          $arr[$row['activityID']]['costs'] = (double)$row['costs'];
        }
      }
      return $arr;
  }

  /**
  * Set field status for users to 1 if user is a group leader, otherwise to 2.
  * Admin status will never be changed.
  * Calling public function should start and end sql transaction.
  *
  * @author sl
  */
  public function update_leader_status() {
      $query = "UPDATE " . $this->kga['server_prefix'] . "users SET status = 2 WHERE status = 1";
      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('update_leader_status');
          return false;
      }

      $query = "UPDATE " . $this->kga['server_prefix'] . "users AS user," . $this->kga['server_prefix'] . "groupleaders AS leader SET status = 1 WHERE status = 2 AND user.userID = leader.userID";
      $result = $this->conn->Query($query);
      if ($result == false) {
          $this->logLastError('update_leader_status');
          return false;
      }

      return true;
  }

  /**
  * Save rate to database.
  *
  * @author sl
  */
  public function save_rate($userID,$projectID,$activityID,$rate) {
    // validate input
    if ($userID == NULL || !is_numeric($userID)) $userID = "NULL";
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";
    if (!is_numeric($rate)) return false;


    // build update or insert statement
    if ($this->get_rate($userID,$projectID,$activityID) === false)
      $query = "INSERT INTO " . $this->kga['server_prefix'] . "rates VALUES($userID,$projectID,$activityID,$rate);";
    else
      $query = "UPDATE " . $this->kga['server_prefix'] . "rates SET rate = $rate WHERE ".
    (($userID=="NULL")?"userID is NULL":"userID = $userID"). " AND ".
    (($projectID=="NULL")?"projectID is NULL":"projectID = $projectID"). " AND ".
    (($activityID=="NULL")?"activityID is NULL":"activityID = $activityID");

    $result = $this->conn->Query($query);

    if ($result == false) {
      $this->logLastError('save_rate');
      return false;
    }
    else
      return true;
  }

  /**
  * Read rate from database.
  *
  * @author sl
  */
  public function get_rate($userID,$projectID,$activityID) {
    // validate input
    if ($userID == NULL || !is_numeric($userID)) $userID = "NULL";
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";


    $query = "SELECT rate FROM " . $this->kga['server_prefix'] . "rates WHERE ".
    (($userID=="NULL")?"userID is NULL":"userID = $userID"). " AND ".
    (($projectID=="NULL")?"projectID is NULL":"projectID = $projectID"). " AND ".
    (($activityID=="NULL")?"activityID is NULL":"activityID = $activityID");

    $result = $this->conn->Query($query);

    if ($this->conn->RowCount() == 0)
      return false;

    $data = $this->conn->rowArray(0,MYSQL_ASSOC);
    return $data['rate'];
  }

  /**
  * Remove rate from database.
  *
  * @author sl
  */
  public function remove_rate($userID,$projectID,$activityID) {
    // validate input
    if ($userID == NULL || !is_numeric($userID)) $userID = "NULL";
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";


    $query = "DELETE FROM " . $this->kga['server_prefix'] . "rates WHERE ".
    (($userID=="NULL")?"userID is NULL":"userID = $userID"). " AND ".
    (($projectID=="NULL")?"projectID is NULL":"projectID = $projectID"). " AND ".
    (($activityID=="NULL")?"activityID is NULL":"activityID = $activityID");

    $result = $this->conn->Query($query);

    if ($result === false) {
      $this->logLastError('remove_rate');
      return false;
    }
    else
      return true;
  }

  /**
  * Query the database for the best fitting rate for the given user, project and activity.
  *
  * @author sl
  */
  public function get_best_fitting_rate($userID,$projectID,$activityID) {
    // validate input
    if ($userID == NULL || !is_numeric($userID)) $userID = "NULL";
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";



    $query = "SELECT rate FROM " . $this->kga['server_prefix'] . "rates WHERE
    (userID = $userID OR userID IS NULL)  AND
    (projectID = $projectID OR projectID IS NULL)  AND
    (activityID = $activityID OR activityID IS NULL)
    ORDER BY userID DESC, activityID DESC , projectID DESC
    LIMIT 1;";

    $result = $this->conn->Query($query);

    if ($result === false) {
      $this->logLastError('get_best_fitting_rate');
      return false;
    }

    if ($this->conn->RowCount() == 0)
      return false;

    $data = $this->conn->rowArray(0,MYSQL_ASSOC);
    return $data['rate'];
  }

  /**
  * Query the database for all fitting rates for the given user, project and activity.
  *
  * @author sl
  */
  public function allFittingRates($userID,$projectID,$activityID) {
    // validate input
    if ($userID == NULL || !is_numeric($userID)) $userID = "NULL";
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";



    $query = "SELECT rate, userID, projectID, activityID FROM " . $this->kga['server_prefix'] . "rates WHERE
    (userID = $userID OR userID IS NULL)  AND
    (projectID = $projectID OR projectID IS NULL)  AND
    (activityID = $activityID OR activityID IS NULL)
    ORDER BY userID DESC, activityID DESC , projectID DESC;";

    $result = $this->conn->Query($query);

    if ($result === false) {
      $this->logLastError('allFittingRates');
      return false;
    }

    return $this->conn->RecordsArray(MYSQL_ASSOC);
  }

  /**
  * Save fixed rate to database.
  *
  * @author sl
  */
  public function save_fixed_rate($projectID,$activityID,$rate) {
    // validate input
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";
    if (!is_numeric($rate)) return false;


    // build update or insert statement
    if ($this->get_fixed_rate($projectID,$activityID) === false)
      $query = "INSERT INTO " . $this->kga['server_prefix'] . "fixedRates VALUES($projectID,$activityID,$rate);";
    else
      $query = "UPDATE " . $this->kga['server_prefix'] . "fixedRates SET rate = $rate WHERE ".
    (($projectID=="NULL")?"projectID is NULL":"projectID = $projectID"). " AND ".
    (($activityID=="NULL")?"activityID is NULL":"activityID = $activityID");

    $result = $this->conn->Query($query);

    if ($result == false) {
      $this->logLastError('save_fixed_rate');
      return false;
    }
    else
      return true;
  }

  /**
  * Read fixed rate from database.
  *
  * @author sl
  */
  public function get_fixed_rate($projectID,$activityID) {
    // validate input
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";


    $query = "SELECT rate FROM " . $this->kga['server_prefix'] . "fixedRates WHERE ".
    (($projectID=="NULL")?"projectID is NULL":"projectID = $projectID"). " AND ".
    (($activityID=="NULL")?"activityID is NULL":"activityID = $activityID");

    $result = $this->conn->Query($query);

    if ($result === false) {
      $this->logLastError('get_fixed_rate');
      return false;
    }

    if ($this->conn->RowCount() == 0)
      return false;

    $data = $this->conn->rowArray(0,MYSQL_ASSOC);
    return $data['rate'];
  }

  /**
   *
   * get the whole budget used for the activity
   * @param integer $projectID
   * @param integer $activityID
   */
  public function get_budget_used($projectID,$activityID) {
  	$timeSheet = $this->get_timeSheet(0, time(), null, null, array($projectID), array($activityID));
  	$budgetUsed = 0;
  	if(is_array($timeSheet)) {
	  	foreach($timeSheet as $timeSheetEntry) {
	  		$budgetUsed+= $timeSheetEntry['wage_decimal'];
	  	}
  	}
  	return $budgetUsed;
  }

  /**
  * Read activity budgets
  *
  * @author mo
  */
  public function get_activity_budget($projectID,$activityID) {
    // validate input
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";


    $query = "SELECT budget, approved, effort FROM " . $this->kga['server_prefix'] . "projects_activities WHERE ".
    (($projectID=="NULL")?"projectID is NULL":"projectID = $projectID"). " AND ".
    (($activityID=="NULL")?"activityID is NULL":"activityID = $activityID");

    $result = $this->conn->Query($query);

    if ($result === false) {
      $this->logLastError('get_activity_budget');
      return false;
    }
    $data = $this->conn->rowArray(0,MYSQL_ASSOC);

  	$timeSheet = $this->get_timeSheet(0, time(), null, null, array($projectID), array($activityID));
  	foreach($timeSheet as $timeSheetEntry)
    {
        if (isset($timeSheetEntry['budget'])) {
    	    $data['budget']+= $timeSheetEntry['budget'];
        }
        if (isset($timeSheetEntry['approved'])) {
        	$data['approved']+= $timeSheetEntry['approved'];
        }
  	}
    return $data;
  }

  /**
  * Remove fixed rate from database.
  *
  * @author sl
  */
  public function remove_fixed_rate($projectID,$activityID) {
    // validate input
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";


    $query = "DELETE FROM " . $this->kga['server_prefix'] . "fixedRates WHERE ".
    (($projectID=="NULL")?"projectID is NULL":"projectID = $projectID"). " AND ".
    (($activityID=="NULL")?"activityID is NULL":"activityID = $activityID");

    $result = $this->conn->Query($query);

    if ($result === false) {
      $this->logLastError('remove_fixed_rate');
      return false;
    }
    else
      return true;
  }

  /**
  * Query the database for the best fitting fixed rate for the given user, project and activity.
  *
  * @author sl
  */
  public function get_best_fitting_fixed_rate($projectID,$activityID) {
    // validate input
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";



    $query = "SELECT rate FROM " . $this->kga['server_prefix'] . "fixedRates WHERE
    (projectID = $projectID OR projectID IS NULL)  AND
    (activityID = $activityID OR activityID IS NULL)
    ORDER BY activityID DESC , projectID DESC
    LIMIT 1;";

    $result = $this->conn->Query($query);

    if ($result === false) {
      $this->logLastError('get_best_fitting_fixed_rate');
      return false;
    }

    if ($this->conn->RowCount() == 0)
      return false;

    $data = $this->conn->rowArray(0,MYSQL_ASSOC);
    return $data['rate'];
  }

  /**
  * Query the database for all fitting fixed rates for the given user, project and activity.
  *
  * @author sl
  */
  public function allFittingFixedRates($projectID,$activityID) {
    // validate input
    if ($projectID == NULL || !is_numeric($projectID)) $projectID = "NULL";
    if ($activityID == NULL || !is_numeric($activityID)) $activityID = "NULL";



    $query = "SELECT rate, projectID, activityID FROM " . $this->kga['server_prefix'] . "fixedRates WHERE
    (projectID = $projectID OR projectID IS NULL)  AND
    (activityID = $activityID OR activityID IS NULL)
    ORDER BY activityID DESC , projectID DESC;";

    $result = $this->conn->Query($query);

    if ($result === false) {
      $this->logLastError('allFittingFixedRates');
      return false;
    }

    return $this->conn->RecordsArray(MYSQL_ASSOC);
  }

  /**
  * Save a new secure key for a user to the database. This key is stored in the users cookie and used
  * to reauthenticate the user.
  *
  * @author sl
  */
  public function user_loginSetKey($userId,$keymai) {
    $p = $this->kga['server_prefix'];

    $query = "UPDATE ${p}users SET secure='$keymai',ban=0,banTime=0 WHERE userID='".
      mysql_real_escape_string($userId)."';";
    $this->conn->Query($query);
  }

  /**
  * Save a new secure key for a customer to the database. This key is stored in the clients cookie and used
  * to reauthenticate the customer.
  *
  * @author sl
  */
  public function customer_loginSetKey($customerId,$keymai) {
    $p = $this->kga['server_prefix'];

    $query = "UPDATE ${p}customers SET secure='$keymai' WHERE customerID='".
      mysql_real_escape_string($customerId)."';";
    $this->conn->Query($query);
  }

  /**
  * Update the ban status of a user. This increments the ban counter.
  * Optionally it sets the start time of the ban to the current time.
  *
  * @author sl
  */
  public function loginUpdateBan($userId,$resetTime = false) {
      $table = $this->getUserTable();

      $filter ['userID']  = MySQL::SQLValue($userId);

      $values ['ban']       = "ban+1";
      if ($resetTime)
        $values ['banTime'] = MySQL::SQLValue(time(),MySQL::SQLVALUE_NUMBER);

      $query = MySQL::BuildSQLUpdate($table, $values, $filter);

      $this->conn->Query($query);
  }


  /**
   * Return all rows for the given sql query.
   *
   * @param string $query the sql query to execute
   */
  public function queryAll($query) {
    return $this->conn->QueryArray($query);
  }
  
  /**
   * checks if given $projectId exists in the db
   * 
   * @param int $projectId
   * @return bool
   */
  public function isValidProjectId($projectId)
  {
  	
  	$table = $this->getProjectTable();
	$filter = array('projectID' => $projectId, 'trash' => 0);
	return $this->rowExists($table, $filter);
  }
  
  /**
   * checks if given $activityId exists in the db
   * 
   * @param int $activityId
   * @return bool
   */
  public function isValidActivityId($activityId)
  {
  	
  	$table = $this->getActivityTable();
	$filter = array('activityID' => $activityId, 'trash' => 0);
	return $this->rowExists($table, $filter);
  }
  
  
 /**
   * checks if a given db row based on the $idColumn & $id exists
   * @param string $table
   * @param array $filter
   * @return bool
   */
  protected function rowExists($table, Array $filter)
  {
	$select = $this->conn->SelectRows($table, $filter);
	
	if(!$select) {
		$this->logLastError('rowExists');
		return false;
	}
	else 
	{
		$rowExits = (bool)$this->conn->RowArray(0, MYSQL_ASSOC);
		return $rowExits;
	}
  }
  
}
