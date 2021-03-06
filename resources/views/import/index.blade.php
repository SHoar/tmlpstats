@extends('template')

@section('content')
    <h1>Validate Stats Sheet</h1>

    <p>Brought to you by the global statistician body. We're always looking to improve this tool. If you have any suggestions or issues, please communicate them to your regional statisitician.</p>

    @if ($showUploadForm)
        @include('import.uploadForm', ['formAction' => '/import', 'expectedDate' => $expectedDate, 'showReportCheckSettings' => $showReportCheckSettings])
    @endif

    @if (isset($results))
        @include('import.upload', ['results' => $results])
    @endif
@endsection