<?php
/**
 * BSCS curriculum, shown on curriculum.php.
 * Structure: year level > semester > subjects.
 * 'prereq' is a subject code (or '' when there is none). Numbers written in
 * the source in place of a code are left out, so they are not shown as prerequisites.
 */
return [
    '1st Year' => [
        'First Semester' => [
            ['code' => 'PATHFIT 1',  'title' => 'Movement Competency Training',                   'units' => 2, 'prereq' => ''],
            ['code' => 'NSTP 1-AFROTC', 'title' => 'National Service Training Program 1',          'units' => 3, 'prereq' => ''],
            ['code' => 'GE 6',       'title' => 'Art Appreciation',                              'units' => 3, 'prereq' => ''],
            ['code' => 'GE 5',       'title' => 'Purposive Communication',                       'units' => 3, 'prereq' => ''],
            ['code' => 'GE 4',       'title' => 'Mathematics in the Modern World',               'units' => 3, 'prereq' => ''],
            ['code' => 'FIL 1',      'title' => 'Akademiko sa Wikang Filipino',                  'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 101',    'title' => 'Computer Programming I',                        'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 100',    'title' => 'Introduction to Computing',                     'units' => 3, 'prereq' => ''],
        ],
        'Second Semester' => [
            ['code' => 'PATHFIT 2',  'title' => 'Exercise-based Fitness Activities',             'units' => 2, 'prereq' => 'PATHFIT 1'],
            ['code' => 'NSTP 2-AFROTC', 'title' => 'National Service Training Program 2',        'units' => 3, 'prereq' => 'NSTP 1'],
            ['code' => 'LIT 1',      'title' => 'Philippine Literature',                         'units' => 3, 'prereq' => ''],
            ['code' => 'GE 3',       'title' => 'The Contemporary World',                        'units' => 3, 'prereq' => ''],
            ['code' => 'GE 2',       'title' => 'Readings in Philippine History',                'units' => 3, 'prereq' => ''],
            ['code' => 'GE 1',       'title' => 'Understanding the Self',                        'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 105',    'title' => 'Data Structures & Algorithms',                  'units' => 3, 'prereq' => 'CSC 100, CSC 101'],
            ['code' => 'CSC 104',    'title' => 'Computer Programming II',                       'units' => 3, 'prereq' => 'CSC 100, CSC 101'],
            ['code' => 'CSC 103',    'title' => 'Discrete Structures I',                         'units' => 3, 'prereq' => 'CSC 100'],
        ],
    ],
    '2nd Year' => [
        'First Semester' => [
            ['code' => 'PATHFIT 3',  'title' => 'Dance/Sports/Martial Arts/Group Exercise/Outdoor and Adventure Activities', 'units' => 2, 'prereq' => 'PATHFIT 1'],
            ['code' => 'GE 8',       'title' => 'Ethics',                                        'units' => 3, 'prereq' => ''],
            ['code' => 'GE 7',       'title' => 'Science, Technology and Society',               'units' => 3, 'prereq' => ''],
            ['code' => 'ENG 127',    'title' => 'Business Communication I',                      'units' => 3, 'prereq' => 'GE 5'],
            ['code' => 'CSC 204',    'title' => 'Programming Languages',                         'units' => 3, 'prereq' => 'CSC 104'],
            ['code' => 'CSC 203',    'title' => 'Algorithms and Complexity',                     'units' => 3, 'prereq' => 'CSC 105, CSC 104'],
            ['code' => 'CSC 202',    'title' => 'Discrete Structures II',                        'units' => 3, 'prereq' => 'CSC 103'],
            ['code' => 'CSC 201',    'title' => 'Information Management',                        'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 200',    'title' => 'Object-Oriented Design & Programming',          'units' => 3, 'prereq' => 'CSC 105, CSC 101'],
        ],
        'Second Semester' => [
            ['code' => 'PATHFIT 4',  'title' => 'Dance/Sports/Martial Arts/Group Exercise/Outdoor and Adventure Activities', 'units' => 2, 'prereq' => 'PATHFIT 1'],
            ['code' => 'GE 9',       'title' => 'Life and Works of Rizal',                       'units' => 3, 'prereq' => ''],
            ['code' => 'GE 10',      'title' => 'Environmental Science',                         'units' => 3, 'prereq' => ''],
            ['code' => 'ENG 128',    'title' => 'Business Communication II',                     'units' => 3, 'prereq' => 'ENG 127'],
            ['code' => 'CSC 209',    'title' => 'Graphics & Visual Computing',                   'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 207',    'title' => 'Operating Systems',                             'units' => 3, 'prereq' => 'CSC 200'],
            ['code' => 'CSC 208',    'title' => 'Modelling & Simulation',                        'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 206',    'title' => 'Human Computer Interaction',                    'units' => 3, 'prereq' => 'CSC 104'],
            ['code' => 'CSC 205',    'title' => 'Architecture and Organization',                 'units' => 3, 'prereq' => 'CSC 202, CSC 105'],
        ],
    ],
    '3rd Year' => [
        'First Semester' => [
            ['code' => 'SYS 100',    'title' => 'Principles of Systems Thinking',                'units' => 3, 'prereq' => 'ENG 128'],
            ['code' => 'SERV 100',   'title' => 'Service Culture',                               'units' => 3, 'prereq' => 'ENG 128'],
            ['code' => 'GE 11',      'title' => 'Gender and Society',                            'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 304',    'title' => 'Web Programming',                               'units' => 3, 'prereq' => 'CSC 200, CSC 206'],
            ['code' => 'CSC 303',    'title' => 'Software Engineering I',                        'units' => 3, 'prereq' => 'CSC 200'],
            ['code' => 'CSC 302',    'title' => 'Network and Communication',                     'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 301',    'title' => 'Seminars & Field Trips',                        'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 300',    'title' => 'Systems Analysis & Design',                     'units' => 3, 'prereq' => 'CSC 206'],
            ['code' => 'BPO 1',      'title' => 'Fund. of BPO 1',                                'units' => 3, 'prereq' => 'ENG 128'],
        ],
        'Second Semester' => [
            ['code' => 'MATH 305',   'title' => 'Numerical Analysis',                            'units' => 5, 'prereq' => 'GE 4'],
            ['code' => 'GE 12',      'title' => 'Philippine Popular Culture',                    'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 309',    'title' => 'Automata Theory & Formal Language',             'units' => 3, 'prereq' => 'CSC 203'],
            ['code' => 'CSC 308',    'title' => 'Information Assurance & Security',              'units' => 3, 'prereq' => 'CSC 201, CSC 302'],
            ['code' => 'CSC 307',    'title' => 'Intellectual Property Basic',                   'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 306',    'title' => 'Software Engineering II',                       'units' => 3, 'prereq' => 'CSC 303'],
            ['code' => 'BPO 2',      'title' => 'Fund of BPO 2',                                 'units' => 3, 'prereq' => 'BPO 1, SYS 100, SERV 100'],
            ['code' => 'CSC 305',    'title' => 'Application Development & Emerging Technologies', 'units' => 3, 'prereq' => 'CSC 300'],
        ],
        'Summer' => [
            ['code' => 'CSC 400',    'title' => 'Internship 1 (300 hours)',                      'units' => 3, 'prereq' => ''],
        ],
    ],
    '4th Year' => [
        'First Semester' => [
            ['code' => 'CSC 402',    'title' => 'Thesis 1',                                      'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 401',    'title' => 'Internship 2 (500 hours)',                      'units' => 3, 'prereq' => 'CSC 400'],
        ],
        'Second Semester' => [
            ['code' => 'CSC 405',    'title' => 'Artificial Intelligence',                       'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 404',    'title' => 'Social Issues and Professional Practice',       'units' => 3, 'prereq' => ''],
            ['code' => 'CSC 403',    'title' => 'Thesis 2',                                      'units' => 3, 'prereq' => 'CSC 402'],
        ],
    ],
];
