<?php

namespace App\Http\Controllers;

use App\Http\Controllers\api\V1\FCMController;
use App\Http\Requests\WorkRequestRequest;
use App\Models\Admin;
use App\Models\Equipment;
use App\Models\MaintenanceTechnician;
use App\Models\WorkOrder;
use App\Models\WorkRequest;
use App\Notifications\NewWorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Notifications\NewWorkRequest;

class WorkRequestController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        abort_if(Gate::denies('work_request_access'), 403);

        switch ($request['filter']) {
            case "only":
                $workRequests = auth()->user()->workRequests;
                break;
            case "all":
                $workRequests = WorkRequest::all();
        }


        $data = [
            'workRequests' => $workRequests ?? WorkRequest::all()
        ];

        return view('work_requests.index', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(): View
    {
        abort_if(Gate::denies('work_request_create'), 403);

        if (auth()->user()->hasRole('Client')) {
            $equipments = auth()->user()->department->equipments;
        } else {
            $equipments = Equipment::all();
        }

        $priorities = [
            "Haute", "Moyenne", "Basse"
        ];

        $data = [
            'equipments' => $equipments,
            'priorities' => $priorities,
        ];


        return view('work_requests.create', $data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param WorkRequestRequest $workRequestRequest
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(WorkRequestRequest $workRequestRequest)
    {
        abort_if(Gate::denies('work_request_create'), 403);

        $data = $workRequestRequest->validated();

        $fcmController = new FCMController();

        DB::beginTransaction();

        try {

            $when = now()->addSeconds(10);

            $data['date'] = now()->toDateString();
            $data['hour'] = now()->toTimeString();
            $data['user_id'] = auth()->id();

            $work_request = WorkRequest::create($data);

            $admins = Admin::all();
            $technicians = MaintenanceTechnician::all();

            if (now()->gt(now()->toDateString() . ' 07:30:00') && now()->lt(now()->toDateString() . ' 17:30:00')) {
                //here the admins will make decision
                foreach ($admins as $admin)
                {
                    $admin->user->notify((new NewWorkRequest($work_request, 'a crée une nouvelle demande de travail'))->delay($when));
                }
            } else {

                //here the system automatically chose a user to send him work order

                $maintenance_technician = MaintenanceTechnician::where('status', '=', 1)->withCount('workOrders')->orderBy('work_orders_count', 'asc')->first();

                // if there are agents !!!!!!!!!!!!!!
                if (!empty($maintenance_technician)) {
                    $data = [
                        'date' => now()->toDateString(),
                        'hour' => now()->toTimeString(),
                        'admin_id' => null,
                        'maintenance_technician_id' => $maintenance_technician->id,
                        'work_request_id' => $work_request->id,
                        'type' => "Curatif",
                        'description' => "This order is auto generated by the system",
                    ];

                    $workOrder = WorkOrder::create($data);

                    $workOrder->workOrderLogs()->create([
                        'status' => "created"
                    ]);

                    $work_request->status = 1;

                    $work_request->save();

                    $workOrder->maintenanceTechnician->user->notify((new NewWorkOrder($workOrder, 'Nouveau ordre de travail auto generated'))->delay($when));

                    $work_request->user->notify((new NewWorkRequest($work_request, 'Votre demande est en cours de traitment'))->delay($when));

                    foreach ($admins as $admin) {
                        $admin->user->notify((new NewWorkOrder($workOrder, 'un ordre de travail auto-geerated by system'))->delay($when));
                    }

                    $fcmController->store(
                        "Ordre de Travail", $data['description'], "order", $workOrder->maintenanceTechnician->user->device_token
                    );


                } else {
                    //send an alert email to all admins and agents.

                    if (auth()->user()->hasRole('Client')) {

                        auth()->user()->notify((new NewWorkRequest($work_request, 'Votre demande est bien crée mais il n\'ya pas de technicians pour intervenir'))->delay($when));
                    }

                    foreach ($admins as $admin) {
                        $admin->user->notify((new NewWorkRequest($work_request, 'a crée une nouvelle demande de travail, les techniciens de maintenance sont hors ligne! merci d\'intervenir'))->delay($when));
                    }

                    foreach ($technicians as $technician) {
                        $technician->user->notify((new NewWorkRequest($work_request, 'a crée une nouvelle demande de travail, connecter au systeme'))->delay($when));
                        $fcmController->store(
                            "Ordre de Travail", $data['description'], "order", $technician->user->device_token
                        );
                    }
                }

            }

        } catch (\Exception $e) {
            DB::rollBack();

            Session::flash("error", $e->getMessage());
            return redirect()->back();
        }

        DB::commit();
        return redirect()->route("work_requests.show", $work_request);

    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\WorkRequest $workRequest
     * @return \Illuminate\Http\Response
     */
    public function show(WorkRequest $workRequest): View
    {
        abort_if(Gate::denies('work_request_show'), 403);

        $data = [
            'workRequest' => $workRequest
        ];

        $data['priorities'] = ["Haute", "Moyenne", "Basse"];
        $data['types'] = ['Curatif', 'Préventif'];
        $data['natures'] = ['Eléctrique', 'Mécanique', 'Pneumatique', 'Hydraulique'];
        $data['technicians'] = MaintenanceTechnician::where('status', '=', 1)->with('user')->get();

        if ($workRequest->user_id == auth()->id()) {

            if (auth()->user()->hasRole('Client')) {
                $data['equipments'] = auth()->user()->department->equipments;
            } else {
                $data['equipments'] = Equipment::all();
            }

            return view('work_requests.edit', $data);
        } else {

            return view('work_requests.show', $data);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Models\WorkRequest $workRequest
     * @return \Illuminate\Http\Response
     */
    public function edit(WorkRequest $workRequest)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\WorkRequest $workRequest
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(WorkRequestRequest $request, WorkRequest $workRequest)
    {
        abort_if(Gate::denies('work_request_edit'), 403);

        $workRequest->updateOrFail($request->validated());

        return redirect()->route('work_requests.show', $workRequest);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\WorkRequest $workRequest
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancel(Request $request, WorkRequest $workRequest)
    {

        abort_if(Gate::denies('work_request_edit'), 403);

        $workRequest->updateOrFail(["status" => 3]);

        $workRequest->user->notify(new NewWorkRequest($workRequest, 'Votre demande est annulée'));

        return redirect()->route('work_requests.show', $workRequest);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\WorkRequest $workRequest
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(WorkRequest $workRequest)
    {
        abort_if(Gate::denies('work_request_delete'), 403);

        $workRequest->delete();

        return redirect()->route('work_requests.index');
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\WorkRequest $workRequest
     * @return \Illuminate\Http\Response
     */
    public function print(WorkRequest $workRequest): View
    {
        abort_if(Gate::denies('work_request_show'), 403);

        $data = [
            'workRequest' => $workRequest
        ];

        $data['priorities'] = ["Haute", "Moyenne", "Basse"];
        $data['types'] = ['Curatif', 'Préventif'];
        $data['natures'] = ['Eléctrique', 'Mécanique', 'Pneumatique', 'Hydraulique'];
        $data['technicians'] = MaintenanceTechnician::where('status', '=', 1)->with('user')->get();

        return view('work_requests.print', $data);

    }

}
