# Agent Memory


### Tables & Key Columns

#### `s_applicants`
- `id` (PK)
- `pan_no` 
- `aadhar_no` - last 4 digit of aadhar_no
- `aadhaar_ref_no` —valut ref no to get full addhar 
- `voter_id_no`

#### `s_applications`
- `id` (PK)
- `uuid`
- `primary_applicant_id` (FK -> s_applicants.id)



#### `s_application_applicants`
- `id` (PK)
- `applicant_id`  (FK -> s_applicants.id)
- `application_id` (FK -> s_applications.id)
- `applicant_type` (ENUM ('applicant , co-applicant))

### specifiction 

use file app\Http\Controllers\V1\Applicant\ApplicantController.php.

write a new function applicantDetailsData()
Request date like  : {
    "pan_no" : "CMXPB9537Q",
    "aadhar_no" : 718414144242,
    "voter_id_no" : "ABC123456789" 

}

first find applicant by pan no and store it into new array name applicantsData   
if aadhar_no is there and length of no == 12 then 
 $provider = 'VSoft';
   $params[] = [
            'aadhaarNumber' => $aadharNumber
        ];
        if (strtolower(config('app.env')) === 'production') {
            $aadhaarVaultResponse = \AadhaarVault::worker($provider)
                ->data($params)
                ->createAadhaarReference();
        } else {
            $aadhaarVaultResponse = [
                'verified' => true,
                'aadhaarNumber' => $aadharNumber,
                'response' => [
                    'referenceNumber' => mt_rand(100000000000, 999999999999),
                    'createdTime' => date('Y-m-d H:i:s.v O')
                ]
            ];
        }

        \Log::info('-- VAULT DRIVER createAadhaarReference RESPONSE --');
        \Log::info($aadhaarVaultResponse);
        $responseTime = Carbon::now();
        $rStatus = 'pending';

        if ($aadhaarVaultResponse['verified']) { //success
            $response['verified'] = true;
            $response['aadhaar_ref_no'] = $aadhaarVaultResponse['response']['referenceNumber'];
            $response['aadhaar_vault_timestamp'] = $responseTime;
            $rStatus = 'active';
        } else {
            if (!empty($aadhaarVaultResponse) && !empty($aadhaarVaultResponse['response']) && !empty($aadhaarVaultResponse['response']['errorCode']) && $aadhaarVaultResponse['response']['errorCode'] == 'VT110') {
                $responseArray = explode(' ', $aadhaarVaultResponse['response']['errorMessage']);

                if (!empty($responseArray) && !empty($responseArray[1]) && $responseArray[1] == intval($responseArray[1])) {
                    $aadhaarNoValidate = $this->getAadharFromReference(
                        $responseArray[1]
                    );

                    if (!empty($aadhaarNoValidate) && $aadhaarNoValidate == $aadharNumber) {
                      $ref_no = $responseArray[1];
                    }
                }
            }
        }

find applicant by  aadhaar_ref_no and append into applicantsData

if  voter_id_no then find applicant by voter_id_no and append into applicantsData


update applicantsData = unique applicantsData

the 
 foreach ($applicantsData as $applicantId) {
            $primaryApplications = Applications::where('primary_applicant_id', $applicantId)->pluck('id');
            $coApplicantApplications = ApplicationApplicants::where('applicant_id', $applicantId)->pluck('application_id');

            $allApplicationIds = $primaryApplications
                ->merge($coApplicantApplications)
                // ->merge($guarantorApplications)
                ->unique();

            if ($allApplicationIds->isNotEmpty()) {
                $applicationUUIDs = Applications::whereIn('id', $allApplicationIds)->pluck('uuid')->toArray();

                foreach ($applicationUUIDs as $applicationUUID) {
                    $data[] = [
                        'applicant_id' => $applicantId,
                        'application_uuid' => $applicationUUID
                    ];
                }
            }
        }

