<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\Request;

class BookSearchController extends Controller
{
    /**
     * Search books using TF-IDF and Cosine Similarity.
     */
    public function search(Request $request)
    {
        $query = $request->input('q', '');

        if (empty(trim($query))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Query parameter "q" is required.',
                'data' => []
            ], 400);
        }

        // Fetch all books. In a larger production app, you might use chunking or Elasticsearch.
        // For a dataset of ~1000 records, processing this in memory is efficient enough.
        $books = Book::select(['id', 'title', 'author', 'publisher', 'publication_year'])->get();
        
        if ($books->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'data' => []
            ]);
        }

        // Step 1: Tokenization
        // Using "title" and "author" as searchable fields as requested.
        $documents = [];
        $corpusTerms = [];

        foreach ($books as $book) {
            $text = $book->title . ' ' . $book->author;
            $tokens = $this->tokenize($text);
            
            $documents[$book->id] = [
                'book' => $book,
                'tokens' => $tokens,
            ];
            
            // Build a corpus of all unique terms
            foreach ($tokens as $token) {
                if (!isset($corpusTerms[$token])) {
                    $corpusTerms[$token] = 0;
                }
            }
        }

        $totalDocuments = count($documents);

        // Calculate Term Document Frequency (how many documents contain a specific term)
        foreach ($corpusTerms as $term => &$count) {
            foreach ($documents as $doc) {
                if (in_array($term, $doc['tokens'])) {
                    $count++;
                }
            }
        }
        unset($count);

        // Step 2 & 3: Inverse Document Frequency (IDF)
        // IDF Formula: log(Total Documents / Documents containing the term)
        $idf = [];
        foreach ($corpusTerms as $term => $docCount) {
            $idf[$term] = log10($totalDocuments / ($docCount ?: 1));
        }

        // Step 4: Term Frequency (TF) and Magnitude matching for Documents
        // To accurately calculate Cosine Similarity later, we pre-calculate vector magnitude
        // for every document based on the computed TF-IDF scores for its own terms.
        foreach ($documents as $id => $doc) {
            $tokensCount = count($doc['tokens']);
            $termCounts = array_count_values($doc['tokens']);
            
            $docMagnitudeSq = 0;
            $docVectorMap = [];
            
            foreach ($termCounts as $term => $count) {
                // Term Frequency = occurrences of term / total terms in document
                $tf = $count / $tokensCount;
                $tfidf = $tf * $idf[$term]; // TF * IDF
                
                $docVectorMap[$term] = $tfidf;
                $docMagnitudeSq += ($tfidf * $tfidf);
            }
            
            $documents[$id]['tf_idf_map'] = $docVectorMap;
            $documents[$id]['magnitude'] = sqrt($docMagnitudeSq);
        }

        // Search Query Tokenization
        $queryTokens = $this->tokenize($query);

        if (empty($queryTokens)) {
            return response()->json([
                'status' => 'success',
                'data' => []
            ]);
        }

        // Step 5: Compute TF-IDF for Query Terms
        $queryVectorMap = [];
        $queryTermCounts = array_count_values($queryTokens);
        $queryTokensCount = count($queryTokens);
        $queryMagnitudeSq = 0;
        
        foreach ($queryTermCounts as $term => $count) {
            $tf = $count / $queryTokensCount;
            // Use corpus IDF if known, otherwise 0
            $termIdf = $idf[$term] ?? 0; 
            $tfidf = $tf * $termIdf;
            
            $queryVectorMap[$term] = $tfidf;
            $queryMagnitudeSq += ($tfidf * $tfidf);
        }
        
        $queryMagnitude = sqrt($queryMagnitudeSq);

        // Step 6: Calculate Cosine Similarity
        // Formula: Sum(A_i * B_i) / (Magnitude(A) * Magnitude(B))
        $results = [];
        
        if ($queryMagnitude > 0) {
            foreach ($documents as $id => $doc) {
                if ($doc['magnitude'] == 0) continue;
                
                $dotProduct = 0;
                
                // We only need to multiply terms that exist in both vectors (query and document)
                foreach ($queryVectorMap as $term => $queryTfidf) {
                    if (isset($doc['tf_idf_map'][$term])) {
                        $dotProduct += $queryTfidf * $doc['tf_idf_map'][$term];
                    }
                }
                
                $similarityScore = $dotProduct / ($queryMagnitude * $doc['magnitude']);
                
                // Return only items that have at least some similarity
                if ($similarityScore > 0) {
                    $results[] = [
                        'id' => $doc['book']->id,
                        'title' => $doc['book']->title,
                        'author' => $doc['book']->author,
                        'publisher' => $doc['book']->publisher,
                        'publication_year' => $doc['book']->publication_year,
                        'description' => $doc['book']->description,
                        'similarity_score' => round($similarityScore, 6)
                    ];
                }
            }
        }

        // Step 7: Sort array from highest similarity to lowest
        usort($results, function ($a, $b) {
            return $b['similarity_score'] <=> $a['similarity_score']; // descending
        });

        return response()->json([
            'status' => 'success',
            'query' => $query,
            'total_results' => count($results),
            'data' => $results
        ]);
    }

    /**
     * Helper to tokenize a string: lowercasing, stripping punctuation, splitting by spaces.
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        // Remove simple punctuation (keep letters and numbers)
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        // Split text by whitespace into array
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        return $tokens ?: [];
    }
}
