<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BookController extends Controller
{
    /**
     * Display a listing of the books.
     */
    public function index()
    {
        // For a large dataset, pagination is highly recommended
        $books = Book::latest()->paginate(15);
        
        return response()->json([
            'status' => 'success',
            'data' => $books
        ]);
    }

    /**
     * Store a newly created book in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'accession_number' => 'required|string|unique:books,accession_number',
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'publisher' => 'required|string|max:255',
            'publication_year' => 'nullable|integer|min:1000|max:' . (date('Y') + 1),
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $book = Book::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Book created successfully',
            'data' => $book
        ], 201);
    }

    /**
     * Display the specified book.
     */
    public function show(string $id)
    {
        $book = Book::find($id);

        if (!$book) {
            return response()->json([
                'status' => 'error',
                'message' => 'Book not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $book
        ]);
    }

    /**
     * Update the specified book in storage.
     */
    public function update(Request $request, string $id)
    {
        $book = Book::find($id);

        if (!$book) {
            return response()->json([
                'status' => 'error',
                'message' => 'Book not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'accession_number' => 'sometimes|required|string|unique:books,accession_number,' . $id,
            'title' => 'sometimes|required|string|max:255',
            'author' => 'sometimes|required|string|max:255',
            'publisher' => 'sometimes|required|string|max:255',
            'publication_year' => 'nullable|integer|min:1000|max:' . (date('Y') + 1),
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $book->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Book updated successfully',
            'data' => $book
        ]);
    }

    /**
     * Remove the specified book from storage.
     */
    public function destroy(string $id)
    {
        $book = Book::find($id);

        if (!$book) {
            return response()->json([
                'status' => 'error',
                'message' => 'Book not found'
            ], 404);
        }

        $book->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Book deleted successfully'
        ]);
    }
}
