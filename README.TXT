REGEXP QUESTION TYPE plugin for Moodle
by Joseph Rezeau (and Jean-Michel Vedrine)
this version compatible with Moodle 2.0 ONLY
*** NOT COMPATIBLE WITH Moodle 2.1 onwards !!!
--------------------------------------------------------
INTRODUCTION

This question type (for the Moodle quiz module) aims at a more advanced system of student's response analysis, through the use of regular expresssions. The new question type is an extension of the SHORTANSWER type. It can be used on its own and can also be incorporated into the existing Cloze (embedded) question type, by being coded as {REGEXP} (on the model of {SHORTANSWER}.

COMPLETE ONLINE DOCUMENTATION  at 
http://docs.moodle.org/en/Regular_Expression_Short-Answer_question_type

INSTALLATION

1.-  The zip archive you download from github to your local computer will be named something like "rezeau-moodle20_questiontype_regexp-36e8fee.zip". The end of the name may vary. 

2.- Unzip that zip archive. This will give you a folder named something like "rezeau-moodle-qtype_regexp-36e8fee".

3.- ***Rename*** that folder to "regexp". 

4.- Copy the entire "regexp" folder into your Moodle 2.0 moodle/question/type/ folder. 

When in your Moodle site, login as Admin and visit the Notifications page once for the new regexp question type to be registered in the database. You should see something like these messages (example for a mySql database):
*******************************
regexp plugin needs upgrading
regexp tables have been set up correctly
*******************************