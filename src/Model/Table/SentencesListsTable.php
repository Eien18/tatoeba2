<?php
/**
 * Tatoeba Project, free collaborative creation of multilingual corpuses project
 * Copyright (C) 2009  HO Ngoc Phuong Trang <tranglich@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 *
 * @category PHP
 * @package  Tatoeba
 * @author   HO Ngoc Phuong Trang <tranglich@gmail.com>
 * @license  Affero General Public License
 * @link     http://tatoeba.org
 */
namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Datasource\Exception\RecordNotFoundException;

/**
 * Model for sentences list.
 *
 * @category SentencesLists
 * @package  Models
 * @author   HO Ngoc Phuong Trang <tranglich@gmail.com>
 * @license  Affero General Public License
 * @link     http://tatoeba.org
 */

use Cake\Database\Schema\TableSchema;

class SentencesListsTable extends Table
{
    // We want to make sure that people don't download long lists, which can slow down the server.
    // This is an arbitrary but easy to remember value, and most lists are shorter than this.
    const MAX_COUNT_FOR_DOWNLOAD = 100;

    protected function _initializeSchema(TableSchema $schema)
    {
        $schema->setColumnType('created', 'string');
        $schema->setColumnType('modified', 'string');
        return $schema;
    }

    public function initialize(array $config)
    {
        parent::initialize($config);
        
        $this->belongsTo('Users');
        $this->hasMany('SentencesSentencesLists');
        $this->belongsToMany('Sentences');

        $this->addBehavior('Timestamp');
    }

    /**
     * Retrieves list.
     *
     * @param int $id Id of the list.
     *
     * @return array
     */
    public function getList($id)
    {
        return $this->find(
            'first',
            array(
                'conditions' => array('SentencesList.id' => $id),
                'contain' => array(
                    'User' => array(
                        'fields' => array('User.username')
                    )
                )
            )
        );
    }

    /**
     * Retrieves list with permissions for the current user.
     */
    public function getListWithPermissions($id, $currentUserId)
    {
        $list = $this->get($id, ['contain' => ['Users']]);
        $list['Permissions'] = $this->_getPermissions(
            $list['SentencesList'], $currentUserId
        );

        return $list;
    }

    private function _getPermissions($list, $currentUserId) {
        $visibility = $list['visibility'];
        $editableBy = $list['editable_by'];
        $belongsToUser = $currentUserId == $list['user_id'];
        $numberOfSentences = $list['numberOfSentences'];

        $permissions = array(
            'canView' => $visibility != 'private' || $belongsToUser,
            'canEdit' => $belongsToUser,
            'canAddSentences' => $belongsToUser && $editableBy !== 'no_one',
            'canRemoveSentences' => $belongsToUser || $editableBy == 'anyone',
            'canDownload' => $numberOfSentences <= self::MAX_COUNT_FOR_DOWNLOAD
        );

        return $permissions;
    }

    /**
     * Returns all the sentences lists that are displayed as searchable
     * for the current user. Note that we don't display unlisted lists
     * while they can actually be searched, for example if the owner of
     * an unlisted list gives the search link to another user.
     *
     * @return array
     */
    public function getSearchableLists()
    {
        return $this->find('all', array(
            'conditions' => array(
                'OR' => array(
                    'user_id' => CurrentUser::get('id'),
                    'visibility' => 'public',
                )
            ),
            'fields' => array('id', 'name', 'user_id'),
            'order' => 'name',
        ));
    }

    /**
     * Check if the user is permitted to use the given list
     * as search criterion.
     *
     * @return bool False if the list cannot be searched, the list otherwise.
     */
    public function isSearchableList($listId)
    {
        return $this->find('first', array(
            'conditions' => array(
                'id' => $listId,
                'OR' => array(
                    'user_id' => CurrentUser::get('id'),
                    'NOT' => array('visibility' => 'private')
                )
            ),
            'fields' => array('id', 'user_id', 'name'),
        ));
    }

    /**
     * Returns the sentences lists that the given user can add sentences to.
     *
     * @param int $userId Id of the user.
     *
     * @return array
     */
    public function getUserChoices($userId)
    {
        $results = $this->find(
            "all",
            array(
                "conditions" => array(
                    "OR" => array(
                        "SentencesList.user_id" => $userId,
                        "SentencesList.editable_by" => 'anyone'
                    ),
                    "NOT" => array(
                        "SentencesList.editable_by" => 'no_one'
                    )
                ),
                'fields' => array('id', 'name', 'user_id'),
                'order' => 'name'
            )
        );

        $listsOfUser = array();
        $collaborativeLists = array();

        $currentUserId = CurrentUser::get('id');
        foreach ($results as $result) {
            $listId = $result['SentencesList']['id'];
            $listName = $result['SentencesList']['name'];
            $userId = $result['SentencesList']['user_id'];

            if (empty($listName)) {
                $listName = __('unnamed list');
            }

            if ($currentUserId == $userId) {
                $listsOfUser[$listId] = $listName;
            } else {
                $collaborativeLists[$listId] = $listName;
            }
        }

        $lists['OfUser'] = $listsOfUser;
        $lists['Collaborative'] = $collaborativeLists;

        return $lists;
    }


    /**
     * Returns lists.
     *
     * @param int $userId Id of the user.
     *
     * @return array
     */
    public function getPaginatedLists(
        $search = null, $username = null, $visibility = null, $editableBy = null
    ) {
        $conditions = null;
        if (!empty($search)) {
            $conditions['SentencesLists.name LIKE'] = "%$search%";
        }
        if (!empty($username)) {
            $userId = $this->User->getIdFromUsername($username);
            $conditions['SentencesLists.user_id'] = $userId;
        }
        if (!empty($visibility)) {
            $conditions['SentencesLists.visibility'] = $visibility;
        }
        if (!empty($editableBy)) {
            $conditions['SentencesLists.editable_by'] = $editableBy;
        }

        return [
            'conditions' => $conditions,
            'contain' => [
                'Users' => [
                    'fields' => ['username']
                ]
            ],
            'order' => ['created' => 'DESC'],
            'limit' => 50
        ];
    }


    /**
     *
     *
     *
     */
    public function getSentencesAndTranslationsOnly($listId, $translationLang)
    {
        if (empty($translationLang)) {
            $request = '
            SELECT Sentence.id, Sentence.text
            from sentences_sentences_lists as ssls
            left join sentences as Sentence on ssls.sentence_id = Sentence.id
            where ssls.sentences_list_id = '.$listId
            ;
        } else {
            $request = '
            select Sentence.id, Sentence.text, Translation.text
            from sentences_sentences_lists as ssls
            left join sentences as Sentence on ssls.sentence_id = Sentence.id
            left join
            (select s.id as sentence_id , t.text as text
            from sentences_sentences_lists as ssls
                left join sentences as s on ssls.sentence_id = s.id
                left join sentences_translations as st on (s.id = st.sentence_id)
                left join sentences as t on ( st.translation_id = t.id )
            where ssls.sentences_list_id = '.$listId.'
                and t.lang  = "'.$translationLang.'"
            ) as Translation on Sentence.id = Translation.sentence_id
            where ssls.sentences_list_id = '.$listId
            ;
        }
        $results = $this->query($request);

        return $results;
    }


    /**
     * Returns value of $this->paginate, for paginating sentences of a list.
     *
     * @param int    $id               Id of the list.
     * @param string $translationsLang Language of the translations.
     * @param bool   $isEditable       'true' if the sentences are editable.
     * @param int    $limit            Number of sentences per page.
     *
     * @return array
     */
    public function paramsForPaginate($id, $translationsLang, $isEditable, $limit)
    {
        $sentenceParams = array(
            'Transcription',
        );

        if ($isEditable) {
            $sentenceParams['User'] = array(
                "fields" => array("id", "username")
            );
        }

        if ($translationsLang != null) {
            // All
            $sentenceParams['Translation'] = array(
                'Transcription',
            );
            // Specific language
            if ($translationsLang != 'und') {
                $sentenceParams['Translation']['conditions'] = array(
                    "lang" => $translationsLang
                );
            }
        }

        $params = array(
            'SentencesSentencesLists' => array(
                'limit' => $limit,
                'conditions' => array('sentences_list_id' => $id),
                'contain' => array(
                    'Sentence' => $sentenceParams
                )
            )
        );

        return $params;
    }


    /**
     * Returns true if list belongs to current user OR is collaborative.
     *
     * @param int $listId Id of list.
     * @param int $userId Id of user.
     *
     * @return bool
     */
    public function isEditableByCurrentUser($listId, $userId)
    {
        $list = $this->find(
            'first',
            array(
                "conditions" => array(
                    "SentencesList.id" => $listId,
                    "OR" => array(
                        "user_id" => $userId,
                        "editable_by" => 'anyone'
                    )
                ),
                "fields" => array(
                    'user_id',
                    'editable_by'
                )
            )
        );

        return !empty($list);
    }

    /**
     * Returns true if list belongs to user.
     *
     * @param int $listId Id of list.
     * @param int $userId Id of user.
     *
     * @return bool
     */
    public function belongsTotUser($listId, $userId)
    {
        $list = $this->find(
            'first',
            array(
                "conditions" => array(
                    "SentencesList.id" => $listId,
                    "user_id" => $userId
                )
            )
        );

        return !empty($list);
    }


    /**
     * Add sentence to list.
     *
     * @param int $sentenceId Id of the sentence.
     * @param int $listId     Id of the list.
     *
     * @return array
     */
    public function addSentenceToList($sentenceId, $listId, $currentUserId)
    {
        try {
            $sentence = $this->Sentences->get($sentenceId);
        } catch (RecordNotFoundException $e) {
            return array();
        }

        try {
            $list = $this->get($listId);
        } catch (RecordNotFoundException $e) {
            return array();
        }

        if (!$list->isEditableBy($currentUserId)) {
            return array();
        }

        $list->sentences = [$sentence];

        try {
            $result = $this->save($list);
            if (!empty($result)) {
                $this->_incrementNumberOfSentencesToList($listId);
            }
            return $result->saved_old_format;
        } catch (\PDOException $e) {
            return array();
        }
    }


    /**
     * get all the list of a given user
     *
     * @param int $username Username of the user
     *
     * @return array
     */
    public function getPaginatedUserLists($username)
    {
        $userId = $this->User->getIdFromUsername($username);

        $paginate = array(
            'conditions' => array(
                "SentencesList.user_id" => $userId,
            ),
            'contain' => array(
                'User' => array(
                    'fields' => array('username')
                )
            ),
            'order' => 'name',
            'limit' => 20
        );

        return $paginate;

    }


    /**
     * Remove sentence from list.
     *
     * @param int $sentenceId Id of the sentence.
     * @param int $listId     Id of the list.
     *
     * @return array
     */
    public function removeSentenceFromList($sentenceId, $listId, $currentUserId)
    {
        try {
            $list = $this->get($listId);
        } catch (RecordNotFoundException $e) {
            return false;
        }
        
        if (!$list->isEditableBy($currentUserId)) {
            return false;
        }

        try {
            $sentence = $this->Sentences->get($sentenceId);
        } catch (RecordNotFoundException $e) {
            return false;    
        }

        $id = $sentence->id;
        $isDeleted = $this->Sentences->unlink($list, [$sentence]);

        if ($isDeleted) {
            $this->_decrementNumberOfSentencesToList($listId);
        }

        return $isDeleted;
    }


    /**
     * Increment number of sentence to list.
     *
     * @param int $listId Id of the list.
     *
     * @return boolean
     */
    private function _incrementNumberOfSentencesToList($listId)
    {
        return $this->updateAll(
            array(
                'numberOfSentences'=>'numberOfSentences+1'
            ),
            array('SentencesList.id'=>$listId)
        );
    }


    /**
     * Decrement number of sentence to list.
     *
     * @param int $listId Id of the list.
     *
     * @return boolean
     */
    private function _decrementNumberOfSentencesToList($listId)
    {
        $success = $this->updateAll(
            array('numberOfSentences'=>'numberOfSentences-1'),
            array('SentencesList.id'=>$listId)
        );

        return $success;
    }


    /**
     * Returns name of the list of given id.
     *
     * @param id $listId Id of the list.
     *
     * @return string
     */
    public function getNameForListWithId($listId)
    {
        $result = $this->find(
            'first',
            array(
                'conditions' => array(
                    'id' => $listId
                ),
                'fields' => array('name')
            )
        );

        return $result['SentencesList']['name'];
    }


    /**
     * Add new sentence to list.
     *
     * @param int    $listId
     * @param string $sentenceText
     * @param string $sentenceLang
     *
     * @return bool
     */
    public function addNewSentenceToList($listId, $sentenceText, $sentenceLang, $currentUserId)
    {
        // Checking if user can add to list.
        $userLevel = $this->Users->getLevelOfUser($currentUserId);
        $canAdd = $userLevel > -1;
        if (!$canAdd) {
            return false;
        }

        // Saving sentence
        $sentenceSaved = $this->Sentences->saveNewSentence(
            $sentenceText, $sentenceLang, $currentUserId
        );
        if (!$sentenceSaved) {
            return false;
        }

        // Adding to list
        $sentenceId = $sentenceSaved->id;
        if ($this->addSentenceToList($sentenceId, $listId, $currentUserId)) {
            $sentence = $this->Sentences->get($sentenceId, ['contain' => 'Users']);
            return $sentence->old_format;
        } else {
            return false;
        }
    }


    /**
     * Get number of sentences in list.
     *
     * @param int $listId Id of the list.
     *
     * @return int
     */
    public function getNumberOfSentences($listId)
    {
        $count = $this->SentencesSentencesLists->find(
            'count',
            array(
                'conditions' => array(
                    'sentences_list_id' => $listId
                )
            )
        );

        return $count;
    }

    /**
     * Create new list.
     */
    public function createList($name, $currentUserId) 
    {
        $name = trim($name);

        if (empty($name)) {
            return false;
        }

        $data = $this->newEntity();
        $data->name = $name;
        $data->user_id = $currentUserId;

        return $this->save($data)->old_format;
    }

    /**
     * Delete list.
     */
    public function deleteList($listId, $currentUserId) 
    {
        $list = $this->get($listId);
        if ($list->isEditableBy($currentUserId)) {
            return $this->delete($list);
        } else {
            return false;
        }
    }

    /**
     * Edit name.
     */
    public function editName($listId, $newName, $currentUserId)
    {
        $list = $this->get($listId);
        if ($list->isEditableBy($currentUserId)) {
            $list->name = $newName;
            return $this->save($list)->old_format;
        } else {
            return false;
        }
    }

    /**
     * Edit visibility or editable_by option.
     */
    public function editOption($listId, $option, $value, $currentUserId)
    {
        $allowedOptions = array('visibility', 'editable_by');
        $list = $this->get($listId);
        $belongsToUser = $list->user_id == $currentUserId;

        if ($belongsToUser && in_array($option, $allowedOptions)) {
            $list->{$option} = $value;
            return $this->save($list)->old_format;
        } else {
            return array();
        }
    }
}
